#!/usr/bin/env ruby
# frozen_string_literal: true

require 'yaml'

WORKFLOW = File.expand_path('../../.github/workflows/quality.yml', __dir__)
REQUIRED_JOBS = %w[repository compose php_quality].freeze
RESULT_VARIABLES = {
  'repository' => 'REPOSITORY_RESULT',
  'compose' => 'COMPOSE_RESULT',
  'php_quality' => 'PHP_RESULT'
}.freeze

def security_errors(config)
  errors = []
  jobs = config.is_a?(Hash) ? config['jobs'] : nil
  return ['quality workflow must have a jobs mapping'] unless jobs.is_a?(Hash)

  (REQUIRED_JOBS + ['security_gate']).each do |job_name|
    errors << "missing mandatory job: #{job_name}" unless jobs[job_name].is_a?(Hash)
  end

  REQUIRED_JOBS.each do |name|
    job = jobs[name]
    next unless job.is_a?(Hash)

    errors << "#{name}: job-level if can skip a mandatory job" if job.key?('if')
    steps = job['steps'].is_a?(Array) ? job['steps'] : []
    checkout = steps.find { |step| step.is_a?(Hash) && step['uses'].to_s.start_with?('actions/checkout@') }
    reference = checkout.is_a?(Hash) ? checkout.fetch('with', {})['ref'].to_s : ''
    unless reference.include?('github.event.pull_request.head.sha') && reference.include?('github.sha')
      errors << "#{name}: checkout is not pinned to the exact pull-request HEAD"
    end
    has_sha_check = steps.any? do |step|
      step.is_a?(Hash) &&
        step['run'].to_s.include?('git rev-parse HEAD') &&
        step.fetch('env', {}).values.any? { |value| value.to_s.include?('github.event.pull_request.head.sha') }
    end
    errors << "#{name}: explicit checkout SHA check missing" unless has_sha_check
  end

  php = jobs['php_quality']
  if php.is_a?(Hash)
    steps = php['steps'].is_a?(Array) ? php['steps'] : []
    commands = steps.filter_map { |step| step.is_a?(Hash) ? step['run'] : nil }.join("\n")
    %w[composer\ validate composer\ install composer\ audit phpstan\ analyse php-cs-fixer rector psalm phpunit].each do |command|
      errors << "php_quality: mandatory command missing: #{command}" unless commands.include?(command.gsub('\\ ', ' '))
    end
    reports = steps.find do |step|
      step.is_a?(Hash) && step['run'].to_s.include?('var/test-results/psalm.sarif') &&
        step['run'].to_s.include?('var/test-results/junit.xml') &&
        step['run'].to_s.include?('var/test-results/coverage.xml') &&
        step['run'].to_s.include?('! -s')
    end
    errors << 'php_quality: mandatory nonempty report assertion missing' unless reports
    artifact = steps.find do |step|
      step.is_a?(Hash) && step['uses'].to_s.start_with?('actions/upload-artifact@')
    end
    unless artifact && artifact.fetch('with', {})['if-no-files-found'] == 'error'
      errors << 'php_quality: missing reports must fail artifact upload'
    end
  end

  gate = jobs['security_gate']
  if gate.is_a?(Hash)
    errors << 'security_gate: must run even if dependencies fail' unless gate['if'].to_s.strip == 'always()'
    needs = gate['needs'].is_a?(Array) ? gate['needs'] : [gate['needs']].compact
    errors << 'security_gate: must require all three jobs' unless (REQUIRED_JOBS - needs).empty?
    steps = gate['steps'].is_a?(Array) ? gate['steps'] : []
    valid_gate = steps.any? do |step|
      next false unless step.is_a?(Hash)

      env = step['env'].is_a?(Hash) ? step['env'] : {}
      run = step['run'].to_s
      RESULT_VARIABLES.all? do |name, variable|
        env[variable].to_s.include?("needs.#{name}.result") &&
          run.include?("$#{variable}") &&
          run.include?('success')
      end && run.match?(/\bexit\s+1\b/)
    end
    errors << 'security_gate: no fail-closed result check for every mandatory job' unless valid_gate
  end

  errors
end

def clone_config(value)
  Marshal.load(Marshal.dump(value))
end

def check_mutations!(config)
  cases = {
    'skip PHP job' => lambda { |c| c['jobs']['php_quality']['if'] = "false" },
    'drop Compose job' => lambda { |c| c['jobs'].delete('compose') },
    'drop security gate' => lambda { |c| c['jobs'].delete('security_gate') },
    'skip failed dependencies' => lambda { |c| c['jobs']['security_gate']['if'] = 'success()' },
    'omit Compose dependency' => lambda { |c| c['jobs']['security_gate']['needs'].delete('compose') },
    'ignore failed gate' => lambda { |c| c['jobs']['security_gate']['steps'][0]['run'] = 'echo OK' },
    'ignore missing reports' => lambda do |c|
      artifact = c['jobs']['php_quality']['steps'].find { |s| s['uses'].to_s.start_with?('actions/upload-artifact@') }
      artifact['with']['if-no-files-found'] = 'ignore'
    end,
    'omit required report assertion' => lambda do |c|
      c['jobs']['php_quality']['steps'].reject! { |s| s['run'].to_s.include?('! -s') }
    end,
    'checkout wrong commit' => lambda do |c|
      checkout = c['jobs']['php_quality']['steps'].find { |s| s['uses'].to_s.start_with?('actions/checkout@') }
      checkout['with']['ref'] = 'main'
    end
  }

  cases.each do |label, mutate|
    candidate = clone_config(config)
    mutate.call(candidate)
    raise "regression self-test failed: #{label} was accepted" if security_errors(candidate).empty?
  end
end

begin
  config = YAML.load_file(WORKFLOW, aliases: true)
  failures = security_errors(config)
  unless failures.empty?
    failures.each { |message| warn "::error::#{message}" }
    exit 1
  end
  check_mutations!(config)
  puts "Mandatory quality gate accepted; #{REQUIRED_JOBS.length + 1} jobs and 9 negative mutation tests verified."
rescue StandardError => error
  warn "::error::Mandatory quality gate assertion failed: #{error.message}"
  exit 1
end
