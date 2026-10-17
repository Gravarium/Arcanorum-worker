#!/usr/bin/env ruby
# frozen_string_literal: true

require 'yaml'

WORKFLOW = File.expand_path('../../.github/workflows/quality.yml', __dir__)
REQUIRED_JOBS = %w[repository compose php_quality].freeze
ALL_REQUIRED_JOBS = (REQUIRED_JOBS + ['security_gate']).freeze
RESULT_VARIABLES = {
  'repository' => 'REPOSITORY_RESULT',
  'compose' => 'COMPOSE_RESULT',
  'php_quality' => 'PHP_RESULT'
}.freeze
PHP_COMMAND_GROUPS = {
  'Composer validation, install, and audit' => [
    'composer validate --strict --no-interaction',
    'composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader',
    'composer audit --locked --no-interaction'
  ],
  'PHPStan level 10' => [
    'vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --error-format=github --memory-limit=1G'
  ],
  'PHP-CS-Fixer check' => [
    'vendor/bin/php-cs-fixer check --config=.php-cs-fixer.dist.php --diff --using-cache=no'
  ],
  'Rector dry run' => [
    'vendor/bin/rector process --config=rector.php --dry-run --no-progress-bar --output-format=github'
  ],
  'Psalm taint analysis and report' => [
    'vendor/bin/psalm --config=psalm.xml --taint-analysis --report=var/test-results/psalm.sarif'
  ],
  'PHPUnit with reports and strict failure flags' => [
    'vendor/bin/phpunit --configuration=phpunit.dist.xml --debug --fail-on-risky --fail-on-warning --log-junit=var/test-results/junit.xml --coverage-text --coverage-clover=var/test-results/coverage.xml'
  ]
}.freeze
REPOSITORY_COMMANDS = [
  ['ruby scripts/ci/assert_mandatory_quality_gate.rb'],
  ['python3 scripts/ci/check_repository.py']
].freeze
COMPOSE_COMMANDS = [
  ['docker compose version'],
  ['bash scripts/ci/check_compose.sh']
].freeze

def continue_on_error?(item)
  value = item['continue-on-error']
  !value.nil? && value != false
end

def report_assertion_step?(step)
  run = step['run'].to_s
  [
    'var/test-results/junit.xml',
    'var/test-results/coverage.xml',
    'var/test-results/psalm.sarif',
    '! -s'
  ].all? { |marker| run.include?(marker) }
end

def report_upload_step?(step)
  return false unless step['uses'].to_s.start_with?('actions/upload-artifact@')

  options = step['with'].is_a?(Hash) ? step['with'] : {}
  options['path'].to_s.include?('var/test-results/junit.xml') &&
    options['path'].to_s.include?('var/test-results/coverage.xml') &&
    options['path'].to_s.include?('var/test-results/psalm.sarif')
end

def mandatory_step?(step)
  step.is_a?(Hash) && !step.key?('if') && !continue_on_error?(step)
end

def shell_command_at_top_level?(run, command)
  return false if run.match?(/^\s*set\s+(?:\+e|\+o\s+errexit)\b/)

  depth = { if: 0, loop: 0, case: 0, function: 0, group: 0 }
  run.lines.each do |raw_line|
    line = raw_line.sub(/\s+#.*\z/, '').strip
    next if line.empty?

    if line == command
      return depth.values.all?(&:zero?)
    end

    depth[:if] += 1 if line.match?(/\Aif\b/)
    depth[:if] -= 1 if line.match?(/\Afi\b/)
    depth[:loop] += 1 if line.match?(/\A(?:for|while|until|select)\b/)
    depth[:loop] -= 1 if line.match?(/\Adone\b/)
    depth[:case] += 1 if line.match?(/\Acase\b/)
    depth[:case] -= 1 if line.match?(/\Aesac\b/)
    depth[:function] += 1 if line.match?(/\Afunction\b|\A[A-Za-z_][A-Za-z0-9_]*\s*\(\)\s*\{/)
    depth[:function] -= 1 if line.match?(/\A\}/)
    depth[:group] += 1 if line.match?(/\A\(/)
    depth[:group] -= 1 if line.match?(/\A\)/)
  end

  false
end

def contains_mandatory_run_step?(steps, commands)
  steps.any? do |step|
    next false unless mandatory_step?(step)

    commands.all? { |command| shell_command_at_top_level?(step['run'].to_s, command) }
  end
end

def gate_script_fails_closed?(run)
  lines = run.lines.map(&:strip).reject(&:empty?)
  condition = RESULT_VARIABLES.values.map { |variable| %([ "$#{variable}" != success ]) }.join(' || ')
  lines.length == 4 &&
    lines[0] == "if #{condition}; then" &&
    lines[1].match?(/\Aecho\s+/) &&
    lines[2] == 'exit 1' &&
    lines[3] == 'fi'
end

def security_errors(config)
  errors = []
  jobs = config.is_a?(Hash) ? config['jobs'] : nil
  return ['quality workflow must have a jobs mapping'] unless jobs.is_a?(Hash)

  ALL_REQUIRED_JOBS.each do |job_name|
    errors << "missing mandatory job: #{job_name}" unless jobs[job_name].is_a?(Hash)
  end

  ALL_REQUIRED_JOBS.each do |name|
    job = jobs[name]
    next unless job.is_a?(Hash)

    if REQUIRED_JOBS.include?(name) && job.key?('if')
      errors << "#{name}: job-level if can skip a mandatory job"
    end
    if name == 'security_gate' && job['if'].to_s.strip != 'always()'
      errors << 'security_gate: must run even if dependencies fail'
    end
    errors << "#{name}: continue-on-error can hide job failure" if continue_on_error?(job)

    steps = job['steps'].is_a?(Array) ? job['steps'] : []
    steps.each do |step|
      next unless step.is_a?(Hash)

      if continue_on_error?(step)
        errors << "#{name}: continue-on-error can hide a step failure"
      end

      next unless step.key?('if')

      allowed_report_step = name == 'php_quality' &&
        step['if'].to_s.strip == 'always()' &&
        (report_assertion_step?(step) || report_upload_step?(step))
      errors << "#{name}: conditional step can skip or weaken a required check" unless allowed_report_step
    end

    next unless REQUIRED_JOBS.include?(name)

    checkouts = steps.each_with_index.filter_map do |step, index|
      [step, index] if step.is_a?(Hash) && step['uses'].to_s.start_with?('actions/checkout@')
    end
    if checkouts.empty?
      errors << "#{name}: exact pull-request HEAD checkout missing"
    else
      checkouts.each do |checkout, _index|
        with = checkout['with'].is_a?(Hash) ? checkout['with'] : {}
        reference = with['ref'].to_s
        unless reference.include?('github.event.pull_request.head.sha') && reference.include?('github.sha')
          errors << "#{name}: checkout is not pinned to the exact pull-request HEAD"
        end
      end
    end

    last_checkout_index = checkouts.map(&:last).max
    has_sha_check_after_checkout = steps.each_with_index.any? do |step, index|
      next false unless step.is_a?(Hash) && index > (last_checkout_index || -1)

      env = step['env'].is_a?(Hash) ? step['env'] : {}
      step['run'].to_s.include?('git rev-parse HEAD') &&
        env.values.any? { |value| value.to_s.include?('github.event.pull_request.head.sha') }
    end
    errors << "#{name}: explicit checkout SHA check missing after checkout" unless has_sha_check_after_checkout
  end

  repository_steps = jobs['repository'].is_a?(Hash) && jobs['repository']['steps'].is_a?(Array) ?
    jobs['repository']['steps'] : []
  unless contains_mandatory_run_step?(repository_steps, REPOSITORY_COMMANDS[0])
    errors << 'repository: mandatory CI security guard is missing or can be skipped'
  end
  unless contains_mandatory_run_step?(repository_steps, REPOSITORY_COMMANDS[1])
    errors << 'repository: mandatory repository safety check is missing or can be skipped'
  end

  compose_steps = jobs['compose'].is_a?(Hash) && jobs['compose']['steps'].is_a?(Array) ?
    jobs['compose']['steps'] : []
  unless contains_mandatory_run_step?(compose_steps, COMPOSE_COMMANDS[0])
    errors << 'compose: Docker Compose availability check is missing or can be skipped'
  end
  unless contains_mandatory_run_step?(compose_steps, COMPOSE_COMMANDS[1])
    errors << 'compose: PostgreSQL/Redis integration check is missing or can be skipped'
  end

  php = jobs['php_quality']
  if php.is_a?(Hash)
    steps = php['steps'].is_a?(Array) ? php['steps'] : []
    PHP_COMMAND_GROUPS.each do |label, markers|
      unless contains_mandatory_run_step?(steps, markers)
        errors << "php_quality: #{label} is missing or can be skipped"
      end
    end

    reports = steps.find { |step| step.is_a?(Hash) && report_assertion_step?(step) }
    unless reports && reports['if'].to_s.strip == 'always()' && !continue_on_error?(reports)
      errors << 'php_quality: mandatory nonempty report assertion missing or can be skipped'
    end

    artifact = steps.find { |step| step.is_a?(Hash) && report_upload_step?(step) }
    unless artifact && artifact['if'].to_s.strip == 'always()' &&
      artifact.fetch('with', {})['if-no-files-found'] == 'error' &&
      !continue_on_error?(artifact)
      errors << 'php_quality: missing reports must fail artifact upload'
    end
  end

  gate = jobs['security_gate']
  if gate.is_a?(Hash)
    needs = gate['needs'].is_a?(Array) ? gate['needs'] : [gate['needs']].compact
    errors << 'security_gate: must require all three jobs' unless (REQUIRED_JOBS - needs).empty?
    steps = gate['steps'].is_a?(Array) ? gate['steps'] : []
    valid_gate = steps.any? do |step|
      next false unless mandatory_step?(step)

      env = step['env'].is_a?(Hash) ? step['env'] : {}
      run = step['run'].to_s
      RESULT_VARIABLES.all? do |name, variable|
        env[variable].to_s.include?("needs.#{name}.result")
      end && gate_script_fails_closed?(run)
    end
    errors << 'security_gate: no fail-closed result check for every mandatory job' unless valid_gate
  end

  errors
end

def clone_config(value)
  Marshal.load(Marshal.dump(value))
end

def check_mutations!(config)
  php_steps = config['jobs']['php_quality']['steps']
  repository_steps = config['jobs']['repository']['steps']
  compose_steps = config['jobs']['compose']['steps']
  gate_steps = config['jobs']['security_gate']['steps']

  cases = {
    'skip PHP job' => lambda { |c| c['jobs']['php_quality']['if'] = 'false' },
    'allow PHP job failure' => lambda { |c| c['jobs']['php_quality']['continue-on-error'] = true },
    'drop Compose job' => lambda { |c| c['jobs'].delete('compose') },
    'drop security gate' => lambda { |c| c['jobs'].delete('security_gate') },
    'skip failed dependencies' => lambda { |c| c['jobs']['security_gate']['if'] = 'success()' },
    'omit Compose dependency' => lambda { |c| c['jobs']['security_gate']['needs'].delete('compose') },
    'ignore failed gate' => lambda { |c| c['jobs']['security_gate']['steps'][0]['run'] = 'echo OK' },
    'skip security gate step' => lambda { |c| c['jobs']['security_gate']['steps'][0]['if'] = 'false' },
    'allow security gate step failure' => lambda { |c| c['jobs']['security_gate']['steps'][0]['continue-on-error'] = true },
    'weaken gate result comparison' => lambda do |c|
      c['jobs']['security_gate']['steps'][0]['run'] =
        c['jobs']['security_gate']['steps'][0]['run'].sub('!= success', '= success')
    end,
    'ignore missing reports' => lambda do |c|
      artifact = c['jobs']['php_quality']['steps'].find { |s| report_upload_step?(s) }
      artifact['with']['if-no-files-found'] = 'ignore'
    end,
    'omit required report assertion' => lambda do |c|
      c['jobs']['php_quality']['steps'].reject! { |s| s.is_a?(Hash) && report_assertion_step?(s) }
    end,
    'skip repository security guard step' => lambda do |c|
      step = c['jobs']['repository']['steps'].find { |s| s.is_a?(Hash) && s['run'].to_s.include?('assert_mandatory_quality_gate.rb') }
      step['if'] = 'false'
    end,
    'skip repository content check' => lambda do |c|
      step = c['jobs']['repository']['steps'].find { |s| s.is_a?(Hash) && s['run'].to_s.include?('check_repository.py') }
      step['if'] = 'false'
    end,
    'skip Compose integration step' => lambda do |c|
      step = c['jobs']['compose']['steps'].find { |s| s.is_a?(Hash) && s['run'].to_s.include?('check_compose.sh') }
      step['if'] = 'false'
    end,
    'skip Composer security step' => lambda do |c|
      step = c['jobs']['php_quality']['steps'].find { |s| s.is_a?(Hash) && s['run'].to_s.include?('composer audit') }
      step['if'] = 'false'
    end,
    'skip PHPStan step' => lambda do |c|
      step = c['jobs']['php_quality']['steps'].find { |s| s.is_a?(Hash) && s['run'].to_s.include?('vendor/bin/phpstan analyse') }
      step['if'] = 'false'
    end,
    'allow PHPStan errors' => lambda do |c|
      step = c['jobs']['php_quality']['steps'].find { |s| s.is_a?(Hash) && s['run'].to_s.include?('vendor/bin/phpstan analyse') }
      step['continue-on-error'] = true
    end,
    'skip PHPStan inside a shell condition' => lambda do |c|
      step = c['jobs']['php_quality']['steps'].find { |s| s.is_a?(Hash) && s['run'].to_s.include?('vendor/bin/phpstan analyse') }
      command = step['run'].lines.find { |line| line.strip.start_with?('vendor/bin/phpstan analyse') }.strip
      step['run'] = "if false; then\n  #{command}\nfi"
    end,
    'ignore PHPStan command failure' => lambda do |c|
      step = c['jobs']['php_quality']['steps'].find { |s| s.is_a?(Hash) && s['run'].to_s.include?('vendor/bin/phpstan analyse') }
      step['run'] = step['run'].sub(/vendor\/bin\/phpstan analyse[^\n]*/) { |command| "#{command} || true" }
    end,
    'wrap security gate in a shell condition' => lambda do |c|
      step = c['jobs']['security_gate']['steps'][0]
      step['run'] = "if false; then\n#{step['run']}\nfi"
    end
  }

  cases.each do |label, mutate|
    candidate = clone_config(config)
    mutate.call(candidate)
    raise "regression self-test failed: #{label} was accepted" if security_errors(candidate).empty?
  end
  cases.length
end

begin
  config = YAML.load_file(WORKFLOW, aliases: true)
  failures = security_errors(config)
  unless failures.empty?
    failures.each { |message| warn "::error::#{message}" }
    exit 1
  end
  mutation_count = check_mutations!(config)
  puts "Mandatory quality gate accepted; #{ALL_REQUIRED_JOBS.length} jobs and #{mutation_count} negative mutation tests verified."
rescue StandardError => error
  warn "::error::Mandatory quality gate assertion failed: #{error.message}"
  exit 1
end
