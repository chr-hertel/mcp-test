import { Controller } from '@hotwired/stimulus';

/*
 * The small amount of behaviour a chat needs beyond the Live Component itself.
 *
 * A turn is one HTTP round trip that spawns MCP servers as child processes, so
 * it takes a noticeable moment: the transcript is scrolled to the newest message
 * whenever the component re-renders, the input keeps the focus so the next
 * question can be typed straight away, and the starter buttons fill it in.
 */
export default class extends Controller {
    static targets = ['transcript', 'input'];

    connect() {
        this.scroll();
        this.element.addEventListener('live:render', () => {
            this.scroll();
            this.inputTarget.focus();
        });
    }

    use(event) {
        this.inputTarget.value = event.params.text;
        // The model is bound with "norender", so the component only learns about
        // the value through the event the user would normally produce.
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
        this.element.querySelector('form.chat-composer').requestSubmit();
    }

    scroll() {
        if (this.hasTranscriptTarget) {
            this.transcriptTarget.scrollTop = this.transcriptTarget.scrollHeight;
        }
    }
}
