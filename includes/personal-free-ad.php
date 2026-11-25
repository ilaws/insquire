<?php

/**
 * Render exactly one non-personalized AdSense slot for eligible Personal Free
 * accounts. Missing configuration, billing tables, or eligibility data fails
 * closed without loading Google's script.
 */
function renderPersonalFreeAd(PDO $pdo, int $userId): void
{
    $requestHost = strtolower((string) parse_url('https://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    $personalHost = strtolower((string) parse_url(PERSONAL_APP_URL, PHP_URL_HOST));
    if (
        $userId <= 0 ||
        !ADSENSE_ENABLED ||
        !preg_match('/^ca-pub-[0-9]{16,20}$/', ADSENSE_PUBLISHER_CLIENT_ID) ||
        !preg_match('/^[0-9]{5,20}$/', ADSENSE_PERSONAL_FREE_SLOT_ID) ||
        isEducationHost() ||
        $requestHost !== $personalHost
    ) {
        return;
    }

    try {
        if (getAdEntitlement($pdo, $userId) !== 'contextual_non_personalized_only') {
            return;
        }
    } catch (Throwable $exception) {
        error_log('Ad eligibility check failed closed for user ' . $userId . ': ' . $exception->getMessage());
        return;
    }

    $clientId = e(ADSENSE_PUBLISHER_CLIENT_ID);
    $slotId = e(ADSENSE_PERSONAL_FREE_SLOT_ID);
    ?>
    <aside class="card personal-free-ad" aria-label="Advertisement">
        <p class="muted">Advertisement</p>
        <ins class="adsbygoogle"
             id="personal-free-adsense-slot"
             style="display:block"
             data-ad-client="<?= $clientId ?>"
             data-ad-slot="<?= $slotId ?>"
             data-ad-format="auto"
             data-full-width-responsive="true"></ins>
    </aside>
    <script>
        (function () {
            var client = <?= json_encode(ADSENSE_PUBLISHER_CLIENT_ID, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            var started = false;

            function loadNonPersonalizedAd() {
                if (started) return;
                started = true;
                var queue = window.adsbygoogle = window.adsbygoogle || [];
                queue.requestNonPersonalizedAds = 1;
                var script = document.createElement('script');
                script.async = true;
                script.crossOrigin = 'anonymous';
                script.src = 'https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=' + encodeURIComponent(client);
                script.onload = function () {
                    (window.adsbygoogle = window.adsbygoogle || []).push({});
                };
                document.head.appendChild(script);
            }

            if (typeof window.__tcfapi !== 'function') return;
            window.__tcfapi('addEventListener', 2, function (tcData, success) {
                if (!success || !tcData || started) return;
                if (tcData.eventStatus !== 'tcloaded' && tcData.eventStatus !== 'useractioncomplete') return;
                if (tcData.gdprApplies === false) {
                    loadNonPersonalizedAd();
                    return;
                }
                var purposeOneConsent = !!(tcData.purpose && tcData.purpose.consents && tcData.purpose.consents[1]);
                var googleVendorConsent = !!(tcData.vendor && tcData.vendor.consents && tcData.vendor.consents[755]);
                if (tcData.gdprApplies === true && purposeOneConsent && googleVendorConsent) {
                    loadNonPersonalizedAd();
                }
            });
        }());
    </script>
    <?php
}
