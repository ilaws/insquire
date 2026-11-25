(function () {
    'use strict';

    // The Google Analytics measurement ID comes from server config
    // (ESSAY_GA_MEASUREMENT_ID). With no ID, analytics and the cookie banner stay off.
    var storageKey = 'insquire-analytics-consent';

    if (document.body.dataset.analyticsEnabled !== 'true') return;

    function disableAnalytics() {
        var style = document.createElement('style');
        style.textContent = '#cookie-settings-link{display:none!important}';
        document.head.appendChild(style);
    }

    function isValidId(id) {
        return typeof id === 'string' && /^G-[A-Z0-9]+$/.test(id);
    }

    var inlineId = document.body.dataset.analyticsId;
    if (inlineId !== undefined) {
        if (isValidId(inlineId)) start(inlineId); else disableAnalytics();
    } else {
        fetch('/api/v1/bootstrap.php', { credentials: 'same-origin' })
            .then(function (response) { return response.ok ? response.json() : {}; })
            .then(function (data) {
                if (isValidId(data.ga_measurement_id)) start(data.ga_measurement_id); else disableAnalytics();
            })
            .catch(function () { disableAnalytics(); });
    }

function start(measurementId) {

    function showBanner() {
        var banner = document.getElementById('cookie-consent');
        if (!banner) return;
        banner.hidden = false;
        document.body.classList.add('has-cookie-banner');
    }

    function hideBanner() {
        var banner = document.getElementById('cookie-consent');
        if (!banner) return;
        banner.hidden = true;
        document.body.classList.remove('has-cookie-banner');
    }

    function trackPageView() {
        if (!window.gtag) return;
        var pagePath = window.location.pathname;
        window.gtag('event', 'page_view', {
            page_path: pagePath,
            page_location: window.location.origin + pagePath,
            page_title: document.title
        });
    }

    function loadAnalytics() {
        if (window.gtag) return;

        window.dataLayer = window.dataLayer || [];
        window.gtag = function () {
            window.dataLayer.push(arguments);
        };
        window.gtag('consent', 'default', {
            analytics_storage: 'denied',
            ad_storage: 'denied',
            ad_user_data: 'denied',
            ad_personalization: 'denied'
        });
        window.gtag('consent', 'update', { analytics_storage: 'granted' });
        window.gtag('js', new Date());
        window.gtag('config', measurementId, { anonymize_ip: true, send_page_view: false });

        var script = document.createElement('script');
        script.async = true;
        script.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(measurementId);
        script.onerror = function () {
            console.error('Unable to load Google Analytics.');
        };
        document.head.appendChild(script);
    }

    function saveChoice(choice) {
        try {
            window.localStorage.setItem(storageKey, choice);
        } catch (error) {
            console.warn('Unable to save analytics consent preference:', error);
        }

        if (choice === 'accepted') {
            loadAnalytics();
            if (document.body.dataset.analyticsSpa !== 'true' || document.body.dataset.appReady === 'true') {
                trackPageView();
            }
        }
        hideBanner();
    }

    try {
        var savedChoice = window.localStorage.getItem(storageKey);
        if (savedChoice === 'accepted') {
            loadAnalytics();
            if (document.body.dataset.analyticsSpa !== 'true' || document.body.dataset.appReady === 'true') {
                trackPageView();
            }
        } else if (savedChoice !== 'rejected') {
            showBanner();
        }
    } catch (error) {
        console.warn('Unable to read analytics consent preference:', error);
        showBanner();
    }

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (!(target instanceof Element)) return;

        var choiceButton = target.closest('[data-cookie-choice]');
        if (choiceButton) {
            saveChoice(choiceButton.getAttribute('data-cookie-choice') === 'accept' ? 'accepted' : 'rejected');
            return;
        }

        var settingsLink = target.closest('#cookie-settings-link, #privacy-cookie-settings');
        if (settingsLink) {
            event.preventDefault();
            showBanner();
            document.getElementById('cookie-consent')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    });

    window.addEventListener('insquire:navigation', function () {
        try {
            if (window.localStorage.getItem(storageKey) === 'accepted') trackPageView();
        } catch (error) {
            console.warn('Unable to read analytics consent preference:', error);
        }
    });
}
}());
