import { Controller } from '@hotwired/stimulus';

/*
 * Marks a form's submit button as busy once the form is sent, so a second
 * click cannot post it twice. Restores the button when the page comes back
 * from the back/forward cache or when Turbo finishes the submission.
 */
export default class extends Controller {
    static targets = ['button'];

    connect() {
        this.restore = this.restore.bind(this);
        window.addEventListener('pageshow', this.restore);
        this.element.addEventListener('turbo:submit-end', this.restore);
    }

    disconnect() {
        window.removeEventListener('pageshow', this.restore);
        this.element.removeEventListener('turbo:submit-end', this.restore);
    }

    submit(event) {
        if (!this.hasButtonTarget || event.defaultPrevented) {
            return;
        }
        const button = this.buttonTarget;
        if (button.getAttribute('aria-busy') === 'true') {
            event.preventDefault();
            return;
        }
        this.label = button.textContent;
        button.setAttribute('aria-busy', 'true');
        if (button.dataset.busyLabel) {
            button.textContent = button.dataset.busyLabel;
        }
        // Disabling synchronously would drop the button from the submitted data.
        setTimeout(() => { button.disabled = true; }, 0);
    }

    restore() {
        if (!this.hasButtonTarget || this.label === undefined) {
            return;
        }
        const button = this.buttonTarget;
        button.disabled = false;
        button.removeAttribute('aria-busy');
        button.textContent = this.label;
    }
}
