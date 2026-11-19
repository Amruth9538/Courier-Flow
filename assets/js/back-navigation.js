document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-back-button]').forEach((button) => {
        let hasSameSitePreviousPage = false;

        if (document.referrer) {
            try {
                const previousPage = new URL(document.referrer);
                hasSameSitePreviousPage =
                    previousPage.origin === window.location.origin &&
                    previousPage.href !== window.location.href;
            } catch (error) {
                hasSameSitePreviousPage = false;
            }
        }

        if (button.hasAttribute('data-back-hide-without-history') && !hasSameSitePreviousPage) {
            button.hidden = true;
        }

        button.addEventListener('click', function () {
            const fallback = button.dataset.backFallback || '';

            if (hasSameSitePreviousPage && window.history.length > 1) {
                window.history.back();
            } else if (button.hasAttribute('data-back-hide-without-history')) {
                button.hidden = true;
            } else if (fallback) {
                window.location.assign(fallback);
            } else {
                button.hidden = true;
            }
        });
    });
});
