import { Controller } from '@hotwired/stimulus';

/*
 * Switches the login card between the email link and the password form.
 * CSS hides the link section while the <details> is open; this controller
 * only moves focus and carries a typed address over to the username field.
 */
export default class extends Controller {
    static targets = ['details', 'email', 'username', 'password'];

    toggle() {
        if (!this.hasDetailsTarget) {
            return;
        }
        if (this.detailsTarget.open) {
            if (this.hasEmailTarget && this.hasUsernameTarget && this.usernameTarget.value === '') {
                this.usernameTarget.value = this.emailTarget.value.trim();
            }
            const next = this.usernameTarget.value === '' ? this.usernameTarget : this.passwordTarget;
            // the page itself opened it after a failed attempt: autofocus already placed the caret
            if (!this.element.contains(document.activeElement) || document.activeElement.tagName === 'SUMMARY') {
                next.focus();
            }
        } else if (this.hasEmailTarget && document.activeElement?.tagName === 'SUMMARY') {
            if (this.emailTarget.value === '' && this.hasUsernameTarget && this.usernameTarget.value.includes('@')) {
                this.emailTarget.value = this.usernameTarget.value.trim();
            }
            this.emailTarget.focus();
        }
    }
}
