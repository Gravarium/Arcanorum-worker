(() => {
    const placement = document.querySelector('[data-ad-placement-slot]');
    if (!placement) {
        return;
    }

    const options = [...document.querySelectorAll('[data-ad-placement-format-option]')];
    const updateFormats = () => {
        const allowed = new Set((placement.selectedOptions[0]?.dataset.formats || '').split(',').filter(Boolean));
        for (const option of options) {
            const input = option.querySelector('[data-ad-placement-format]');
            if (!input) {
                continue;
            }

            const isAllowed = allowed.has(input.value);
            option.hidden = !isAllowed;
            input.disabled = !isAllowed;
            if (!isAllowed) {
                input.checked = false;
            }
        }
    };

    placement.addEventListener('change', updateFormats);
    updateFormats();
})();
