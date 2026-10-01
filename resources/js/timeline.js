// Timeline: load older pages while scrolling, keep the URL on the visible month, and
// jump via the month select. Plain vanilla JS; without JS the "Starší" link still works.

function initTimeline(timeline) {
    let loading = false;

    // Append the next page's sections; a month split across pages continues in its existing section.
    const loadMore = async () => {
        const more = timeline.querySelector('[data-timeline-more]');
        const link = more?.querySelector('[data-timeline-next]');
        if (!link || loading) return;

        loading = true;
        link.textContent = 'Načítám…';

        try {
            const response = await fetch(link.href, {
                headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);

            const template = document.createElement('template');
            template.innerHTML = await response.text();

            for (const section of template.content.querySelectorAll('[data-month]')) {
                const sections = timeline.querySelectorAll('[data-month]');
                const last = sections[sections.length - 1];

                if (last?.dataset.month === section.dataset.month) {
                    last.querySelector('[data-month-images]').append(...section.querySelector('[data-month-images]').children);
                } else {
                    more.before(section);
                    headings.observe(section);
                }
            }

            more.replaceWith(template.content.querySelector('[data-timeline-more]') ?? '');
            observeMore();
        } catch {
            link.textContent = 'Starší';
        } finally {
            loading = false;
        }
    };

    const moreObserver = new IntersectionObserver(
        (entries) => entries.some((entry) => entry.isIntersecting) && loadMore(),
        { rootMargin: '800px 0px' },
    );

    const observeMore = () => {
        moreObserver.disconnect();
        const more = timeline.querySelector('[data-timeline-more]');
        if (more) moreObserver.observe(more);
    };

    // Track the month at the top of the viewport: reflect it in the URL (so reload and back
    // return to it) and highlight it in the month index.
    const headings = new IntersectionObserver(
        (entries) => {
            const visible = entries.filter((entry) => entry.isIntersecting);
            if (visible.length === 0) return;

            const month = visible[0].target.dataset.month;
            const url = new URL(window.location.href);
            url.searchParams.set('from', month);
            url.searchParams.delete('cursor');
            window.history.replaceState(null, '', url);

            document.querySelectorAll('[data-month-link]').forEach((link) => {
                link.setAttribute('aria-current', link.dataset.monthLink === month ? 'true' : 'false');
            });
        },
        { rootMargin: '0px 0px -85% 0px' },
    );

    timeline.querySelectorAll('[data-month]').forEach((section) => headings.observe(section));
    observeMore();
}

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-timeline]').forEach(initTimeline);

    document.querySelectorAll('[data-month-jump] select').forEach((select) => {
        select.addEventListener('change', () => select.form.submit());
    });
});
