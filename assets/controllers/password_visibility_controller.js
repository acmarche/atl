import { Controller } from '@hotwired/stimulus';

/*
 * Show / hide toggle for a password field: a pressed/unpressed button that
 * keeps focus, so assistive tech announces the new state. The toggle stays
 * hidden until this controller runs, so without JavaScript the field is a plain one.
 */
export default class extends Controller {
    static targets = ['input', 'toggle'];

    connect() {
        this.toggleTarget.hidden = false;
    }

    toggle() {
        const show = this.inputTarget.type === 'password';
        this.inputTarget.type = show ? 'text' : 'password';
        this.toggleTarget.setAttribute('aria-pressed', show ? 'true' : 'false');
        this.toggleTarget.classList.toggle('active', show);
    }
}
