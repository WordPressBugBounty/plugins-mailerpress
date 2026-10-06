document.addEventListener("DOMContentLoaded", () => {
  const mailerPressOptinForms = document.querySelectorAll(
    ".mailerpress-optin-form",
  );

  mailerPressOptinForms.forEach((form) => {
    if (form.dataset.mailerpressOptinInitialized === "true") {
      return;
    }

    form.dataset.mailerpressOptinInitialized = "true";

    form.addEventListener("submit", async (e) => {
      e.preventDefault();

      const submitButton = form.querySelector('button[type="submit"]');
      const originalBtnText = submitButton.textContent;

      // Remove existing notice
      let oldNotice = form.querySelector(".mailerpress-notice");
      if (oldNotice) oldNotice.remove();

      // Button loading state
      submitButton.disabled = true;

      try {
        const submitForm = async (nonceRefreshed = false) => {
          const formData = new FormData(form);

          // Shortcodes use a JSON array; Gutenberg blocks store a single ID.
          const listValue = (formData.get("mailerpress-list") || "").trim();
          const listIds = listValue.startsWith("[")
            ? JSON.parse(listValue)
            : [listValue];

          // Get double opt-in setting from form data attribute
          const doubleOptinEnabled = form.dataset.doubleOptin === "true";
          const contactStatus = doubleOptinEnabled ? "pending" : "subscribed";

          const payload = {
            contactEmail: formData.get("contactEmail"),
            contactFirstName: formData.get("contactFirstName"),
            contactLastName: formData.get("contactLastName"),
            contactStatus: contactStatus,
            tags: JSON.parse(formData.get("mailerpress-tags") || "[]").map(
              (id) => ({ id }),
            ),
            lists: listIds.filter(Boolean).map((id) => ({ id })),
            opt_in_source: "custom_form",
            website: formData.get("website") || "", // Honeypot field
            lang: document.documentElement.lang || "",
          };

          // Nonce injected server-side: window.mailerpressFormConfig (Gutenberg block)
          // or window.mailerpressOptin (shortcode). Required to authenticate the request.
          const nonce =
            window.mailerpressFormConfig?.nonce ??
            window.mailerpressOptin?.nonce;
          const headers = { "Content-Type": "application/json" };
          if (nonce) headers["X-WP-Nonce"] = nonce;

          const apiUrl =
            window.mailerpressFormConfig?.apiUrl ??
            window.mailerpressOptin?.apiUrl;
          if (!apiUrl) {
            throw new Error("Missing form REST API URL");
          }

          const response = await fetch(apiUrl, {
            method: "POST",
            headers,
            body: JSON.stringify(payload),
          });

          const result = await response.json();

          if (
            !response.ok &&
            ["invalid_nonce", "rest_cookie_invalid_nonce"].includes(
              result?.code,
            ) &&
            !nonceRefreshed
          ) {
            const ajaxUrl =
              window.mailerpressFormConfig?.ajaxUrl ??
              window.mailerpressOptin?.ajaxUrl ??
              "/wp-admin/admin-ajax.php";
            const refreshResponse = await fetch(`${ajaxUrl}?t=${Date.now()}`, {
              method: "POST",
              headers: { "Content-Type": "application/x-www-form-urlencoded" },
              body: "action=mailerpress_refresh_optin_nonce",
              cache: "no-store",
              credentials: "same-origin",
            });
            const refreshResult = await refreshResponse.json();
            const refreshedNonce = refreshResult?.data?.rest_nonce;

            if (
              !refreshResponse.ok ||
              !refreshResult?.success ||
              !refreshedNonce
            ) {
              throw new Error("Unable to refresh form security token");
            }

            if (window.mailerpressFormConfig) {
              window.mailerpressFormConfig.nonce = refreshedNonce;
            }
            if (window.mailerpressOptin) {
              window.mailerpressOptin.nonce = refreshedNonce;
            }

            return submitForm(true);
          }

          return { response, result };
        };

        const { response, result } = await submitForm();

        const noticeEl = document.createElement("div");
        noticeEl.className = "mailerpress-notice";

        if (response.ok) {
          noticeEl.classList.add("success");
          noticeEl.textContent =
            form.dataset.successMessage ||
            window.mailerpressFormConfig?.i18n?.success ||
            "Successfully subscribed!";
          form.reset();

          const redirectUrl = form.dataset.redirectUrl;
          if (redirectUrl) {
            try {
              const url = new URL(redirectUrl, window.location.origin);
              setTimeout(() => {
                window.location.href = url.href;
              }, 1500);
            } catch (e) {
              // Invalid URL — fall through to show success message
            }
          }
        } else {
          noticeEl.classList.add("error");
          noticeEl.textContent =
            form.dataset.errorMessage ||
            result.message ||
            window.mailerpressFormConfig?.i18n?.error ||
            "An error occurred. Please try again.";
        }

        form.appendChild(noticeEl);
        setTimeout(() => noticeEl.remove(), 4000);
      } catch (err) {
        const errorEl = document.createElement("div");
        errorEl.className = "mailerpress-notice error";
        errorEl.textContent =
          form.dataset.errorMessage ||
          window.mailerpressFormConfig?.i18n?.unexpected ||
          "Unexpected error. Please try again later.";
        form.appendChild(errorEl);
        setTimeout(() => errorEl.remove(), 4000);
      } finally {
        submitButton.disabled = false;
        submitButton.textContent = originalBtnText;
      }
    });
  });
});
