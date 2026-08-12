(function () {
  const SWIPE_THRESHOLD = 45;
  const SWIPE_SUPPRESS_MS = 320;

  let activePointer = null;
  let swipeSuppressedUntil = 0;

  const isDiscoverVisible = () => {
    const discoverView = document.getElementById("discover-view");
    return !!discoverView && !discoverView.classList.contains("hidden");
  };

  const markSwipeIfNeeded = (endX, endY) => {
    if (!activePointer) return;

    const deltaX = Math.abs(endX - activePointer.startX);
    const deltaY = Math.abs(endY - activePointer.startY);

    if (deltaX >= SWIPE_THRESHOLD && deltaX >= deltaY) {
      swipeSuppressedUntil = Date.now() + SWIPE_SUPPRESS_MS;
    }

    activePointer = null;
  };

  const openBookModal = (html, sourcePanelKey) => {
    const content = document.getElementById("book-modal-content");
    const modalBook = document.getElementById("book-modal");

    if (!content || !modalBook) return;

    content.innerHTML = html;

    const modalRoot = content.querySelector(".book-modal-v2");
    if (modalRoot) {
      modalRoot.dataset.sourcePanel = sourcePanelKey;
    }

    modalBook.classList.remove("hidden");
  };

  const openDiscoverImage = (discoverImg) => {
    const sourcePanelKey = discoverImg.closest(".discover-panel")?.dataset.panelKey || "discover";
    const rawBookId = String(discoverImg.dataset.bookId || discoverImg.dataset.id || "").trim();
    const discoverBookId = Number.parseInt(rawBookId, 10);

    if (Number.isFinite(discoverBookId) && discoverBookId > 0) {
      if (typeof window.smartlibTrackRecommendation === "function") {
        window.smartlibTrackRecommendation("open", sourcePanelKey, discoverBookId);
      }

      fetch("get_book.php?id=" + discoverBookId)
        .then((res) => res.text())
        .then((html) => openBookModal(html, sourcePanelKey))
        .catch(() => {
          // Non-blocking fallback.
        });
      return;
    }

    const src = discoverImg.getAttribute("src") || "";
    const srcCover = src ? src.split("/").pop() : "";
    const cover = (discoverImg.dataset.cover || srcCover || "").trim();

    if (!cover) return;

    fetch("get_book.php?cover=" + encodeURIComponent(cover))
      .then((res) => res.text())
      .then((html) => {
        openBookModal(html, sourcePanelKey);

        const modalRoot = document.querySelector("#book-modal-content .book-modal-v2");
        const modalBookId = Number.parseInt(String(modalRoot?.dataset.bookId || ""), 10);

        if (Number.isFinite(modalBookId) && modalBookId > 0 && typeof window.smartlibTrackRecommendation === "function") {
          window.smartlibTrackRecommendation("open", sourcePanelKey, modalBookId);
        }
      })
      .catch(() => {
        // Non-blocking fallback.
      });
  };

  document.addEventListener("DOMContentLoaded", () => {
    const discoverSection = document.getElementById("discover-section");
    if (!discoverSection) return;

    discoverSection.addEventListener("pointerdown", (e) => {
      const wrap = e.target.closest(".carousel-books");
      if (!wrap) return;

      activePointer = {
        pointerId: e.pointerId,
        startX: e.clientX,
        startY: e.clientY
      };
    }, true);

    discoverSection.addEventListener("pointerup", (e) => {
      if (!activePointer || activePointer.pointerId !== e.pointerId) return;
      markSwipeIfNeeded(e.clientX, e.clientY);
    }, true);

    discoverSection.addEventListener("pointercancel", (e) => {
      if (!activePointer || activePointer.pointerId !== e.pointerId) return;
      activePointer = null;
    }, true);

    discoverSection.addEventListener("click", (e) => {
      if (!isDiscoverVisible()) return;

      let discoverImg = e.target.closest(".discover-book, .discover-carousel img");

      if (!discoverImg) {
        const discoverWrap = e.target.closest(".discover-panel .carousel-books");
        if (discoverWrap) {
          discoverImg = discoverWrap.querySelector("img.center") || discoverWrap.querySelector("img");
        }
      }

      if (!discoverImg) return;

      if (Date.now() < swipeSuppressedUntil) {
        return;
      }

      e.preventDefault();
      e.stopPropagation();
      openDiscoverImage(discoverImg);
    }, true);
  });
})();