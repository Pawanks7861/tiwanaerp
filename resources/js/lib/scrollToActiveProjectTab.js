/**
 * Scroll the project tab bar so the active tab is visible, without moving the page.
 */

const EDGE_PADDING = 8;
const DELAYED_RENDER_MS = 400;

function prefersReducedMotion() {
    return typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function isFullyVisibleHorizontally(element, container) {
    const elementRect = element.getBoundingClientRect();
    const containerRect = container.getBoundingClientRect();

    return elementRect.left >= containerRect.left + EDGE_PADDING && elementRect.right <= containerRect.right - EDGE_PADDING;
}

function centeredScrollLeft(container, element) {
    const containerRect = container.getBoundingClientRect();
    const elementRect = element.getBoundingClientRect();
    const next = container.scrollLeft + (elementRect.left - containerRect.left) - containerRect.width / 2 + elementRect.width / 2;
    const max = Math.max(0, container.scrollWidth - container.clientWidth);

    return Math.min(max, Math.max(0, next));
}

function scrollTabContainerTo(container, element, { instant = false } = {}) {
    if (!container.contains(element) || isFullyVisibleHorizontally(element, container)) {
        return;
    }

    container.scrollTo({
        left: centeredScrollLeft(container, element),
        top: container.scrollTop,
        behavior: instant || prefersReducedMotion() ? 'auto' : 'smooth',
    });
}

function activeProjectTab(container) {
    return container.querySelector('[aria-current="page"]');
}

function positionActiveTab(container, options) {
    const active = activeProjectTab(container);

    if (!active) {
        return false;
    }

    scrollTabContainerTo(container, active, options);

    return true;
}

/**
 * Find the active project tab and scroll only the horizontal tab bar to it.
 */
export function scrollToActiveProjectTab(container, options = {}) {
    if (!container) {
        return;
    }

    const run = () => positionActiveTab(container, options);

    requestAnimationFrame(() => {
        if (run()) {
            return;
        }

        const observer = new MutationObserver(() => {
            if (run()) {
                observer.disconnect();
            }
        });

        observer.observe(container, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['aria-current'],
        });

        window.setTimeout(() => observer.disconnect(), DELAYED_RENDER_MS);
    });
}
