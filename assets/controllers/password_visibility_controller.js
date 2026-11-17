import { Controller } from '@hotwired/stimulus';

/*
 * Show / hide toggle for a password field. The toggle stays hidden
 * until this controller runs, so without JavaScript the field is a plain one.
 */
export default class extends Controller {
    static targets = ['input', 'toggle'];

    connect() {
        this.toggleTarget.hidden = false;
    }

    toggle() {
        const show = this.inputTarget.type === 'password';
        this.inputTarget.type = show ? 'text' : 'password';
        this.toggleTarget.textContent = show ? 'Masquer' : 'Afficher';
        this.toggleTarget.setAttribute('aria-label', show ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
        this.inputTarget.focus();
    }
}
