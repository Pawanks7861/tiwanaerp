/**
 * Scroll the sidebar nav so the active item is visible, without moving the page.
 */

const EDGE_PADDING = 8;
const DELAYED_RENDER_MS = 400;

function prefersReducedMotion() {
    return typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

export function expandSidebarAncestors(element) {
    let node = element?.parentElement ?? null;

    while (node) {
        if (node instanceof HTMLDetailsElement && !node.open) {
            node.open = true;
        }

        node = node.parentElement;
    }
}

function isFullyVisibleIn(element, container) {
    const elementRect = element.getBoundingClientRect();
    const containerRect = container.getBoundingClientRect();

    return elementRect.top >= containerRect.top && elementRect.bottom <= containerRect.bottom;
}

function scrollSidebarContainerTo(container, element, { instant = false } = {}) {
    if (!container.contains(element) || isFullyVisibleIn(element, container)) {
        return;
    }

    const containerRect = container.getBoundingClientRect();
    const elementRect = element.getBoundingClientRect();
    const current = container.scrollTop;
    let next = current;

    if (elementRect.top < containerRect.top) {
        next = current + (elementRect.top - containerRect.top) - EDGE_PADDING;
    } else if (elementRect.bottom > containerRect.bottom) {
        next = current + (elementRect.bottom - containerRect.bottom) + EDGE_PADDING;
    }

    const max = Math.max(0, container.scrollHeight - container.clientHeight);

    container.scrollTo({
        top: Math.min(max, Math.max(0, next)),
        behavior: instant || prefersReducedMotion() ? 'auto' : 'smooth',
    });
}

function activeSidebarItem(container) {
    return container.querySelector('[aria-current="page"]');
}

function positionActiveItem(container, options) {
    const active = activeSidebarItem(container);

    if (!active) {
        return false;
    }

    expandSidebarAncestors(active);
    scrollSidebarContainerTo(container, activeSidebarItem(container) ?? active, options);

    return true;
}

/**
 * Identify the active sidebar link from the current page, expand nested parents,
 * then scroll only the sidebar container into view.
 */
export function scrollToActiveSidebarItem(container, options = {}) {
    if (!container) {
        return;
    }

    const run = () => positionActiveItem(container, options);

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
            attributeFilter: ['aria-current', 'open'],
        });

        window.setTimeout(() => observer.disconnect(), DELAYED_RENDER_MS);
    });
}
