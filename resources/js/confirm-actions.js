// Confirmation prompts live in data attributes rather than executable HTML
// attributes so the application can enforce a restrictive script-src policy.
document.addEventListener('submit', (event) => {
    const formEvent = event;
    const submitter = formEvent.submitter instanceof HTMLElement
        ? formEvent.submitter
        : document.activeElement;
    const confirmation = submitter instanceof HTMLElement
        ? submitter.dataset.confirm
        : undefined;

    if (typeof confirmation === 'string' && confirmation !== '' && !window.confirm(confirmation)) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }
}, true);
