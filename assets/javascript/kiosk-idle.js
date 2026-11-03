// Kiosk idle auto-return: after IDLE_MS with no touch, mouse, keyboard or
// scroll activity, sign out through the real logout.php (it destroys the
// server-side session, including any library-card view or pending signup,
// then redirects to the kiosk home), so the next person at the kiosk never
// sees the previous patron's account, card or half-filled form.
//
// Loaded only on kiosk-facing pages (index.php and the library card pages),
// never in the admin panel. Configured from its own <script> tag:
//   data-home="1"       this is the kiosk home page (index.php)
//   data-signed-in="1"  a patron/staff session is active
//   data-idle-ms="N"    a longer timeout for this page (library_card_verify.php
//                       uses the emailed code's lifetime, since people step
//                       away to check their phone)
// On the home page with nobody signed in, the search view closed and no
// modal open, there's nothing to reset, so it just keeps waiting.
(function () {
    const DEFAULT_IDLE_MS = 120000;
    const LOGOUT_URL = "logout.php";
    const ACTIVITY_EVENTS = ["pointerdown", "pointermove", "keydown", "wheel", "touchstart", "input", "scroll"];

    const config = document.currentScript?.dataset || {};
    const isHome = config.home === "1";
    const signedIn = config.signedIn === "1";
    const pageIdleMs = Number(config.idleMs);
    const IDLE_MS = pageIdleMs > 0 ? pageIdleMs : DEFAULT_IDLE_MS;

    let lastActivity = Date.now();
    let timer = null;
    let fired = false;

    const homeAtRest = () => {
        if (!isHome || signedIn) return false;
        const searchView = document.getElementById("search-view");
        if (searchView && !searchView.classList.contains("hidden")) return false;
        return !document.querySelector(".modal:not(.hidden)");
    };

    const fire = () => {
        if (fired) return;
        fired = true;
        // Same request the account menu's "Log Out" sends (a POST form to
        // logout.php), just without the confirm step.
        const form = document.createElement("form");
        form.method = "POST";
        form.action = LOGOUT_URL;
        form.hidden = true;
        document.body.appendChild(form);
        form.submit();
    };

    const check = () => {
        const idleFor = Date.now() - lastActivity;
        if (idleFor < IDLE_MS) {
            schedule(IDLE_MS - idleFor);
        } else if (homeAtRest()) {
            lastActivity = Date.now();
            schedule(IDLE_MS);
        } else {
            fire();
        }
    };

    const schedule = (ms) => {
        clearTimeout(timer);
        timer = setTimeout(check, ms);
    };

    // Cheap on every event: just note the time. The single timer re-arms
    // itself for the remaining time when it wakes up early.
    const markActivity = () => {
        lastActivity = Date.now();
    };
    ACTIVITY_EVENTS.forEach((type) => {
        document.addEventListener(type, markActivity, { capture: true, passive: true });
    });

    // Background tabs throttle timers; check as soon as the page is shown again.
    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "visible") check();
    });

    schedule(IDLE_MS);
})();
