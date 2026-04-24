document.addEventListener("DOMContentLoaded", () => {
    const mailerPressOptinForms = document.querySelectorAll('.mailerpress-optin-form');

    mailerPressOptinForms.forEach(form => {
        form.addEventListener('submit', async e => {
            e.preventDefault();

            const submitButton = form.querySelector('button[type="submit"]');
            const originalBtnText = submitButton.textContent;

            // Remove existing notice
            let oldNotice = form.querySelector('.mailerpress-notice');
            if (oldNotice) oldNotice.remove();

            const formData = new FormData(form);

            // Get double opt-in setting from form data attribute
            const doubleOptinEnabled = form.dataset.doubleOptin === 'true';
            const contactStatus = doubleOptinEnabled ? 'pending' : 'subscribed';

            const payload = {
                contactEmail: formData.get('contactEmail'),
                contactFirstName: formData.get('contactFirstName'),
                contactLastName: formData.get('contactLastName'),
                contactStatus: contactStatus,
                tags: JSON.parse(formData.get('mailerpress-tags') || '[]').map(id => ({ id })),
                lists: [formData.get('mailerpress-list')].filter(Boolean).map(id => ({ id })),
                opt_in_source: 'custom_form',
                website: formData.get('website') || '' // Honeypot field
            };

            // Button loading state
            submitButton.disabled = true;

            try {
                const response = await fetch("/wp-json/mailerpress/v1/contact", {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });

                const result = await response.json();

                const noticeEl = document.createElement('div');
                noticeEl.className = 'mailerpress-notice';

                if (response.ok) {
                    noticeEl.classList.add('success');
                    noticeEl.textContent =
                        form.dataset.successMessage || 'Successfully subscribed!';
                    form.reset();
                } else {
                    noticeEl.classList.add('error');
                    noticeEl.textContent =
                        form.dataset.errorMessage || result.message || 'An error occurred. Please try again.';
                }

                form.appendChild(noticeEl);
                setTimeout(() => noticeEl.remove(), 4000);

            } catch (err) {
                const errorEl = document.createElement('div');
                errorEl.className = 'mailerpress-notice error';
                errorEl.textContent =
                    form.dataset.errorMessage || 'Unexpected error. Please try again later.';
                form.appendChild(errorEl);
                setTimeout(() => errorEl.remove(), 4000);
            } finally {
                submitButton.disabled = false;
                submitButton.textContent = originalBtnText;
            }
        });
    });
});
