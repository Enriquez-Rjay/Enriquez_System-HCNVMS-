// Simple modal logic for pop-up forms
const modal = {
  overlay: null,
  el: null,
  show(content) {
    if (!this.overlay) {
      this.overlay = document.createElement('div');
      this.overlay.className = 'fixed inset-0 bg-black/30 z-50 flex items-center justify-center';
      this.el = document.createElement('div');
      this.el.className = 'bg-white rounded-xl shadow-xl max-w-lg w-full p-0 relative';
      this.overlay.appendChild(this.el);
      document.body.appendChild(this.overlay);
      this.overlay.addEventListener('click', (e) => {
        if (e.target === this.overlay) modal.hide();
      });
    }
    this.el.innerHTML = content;
    this.overlay.style.display = 'flex';
  },
  hide() {
    if (this.overlay) this.overlay.style.display = 'none';
  }
};
window.addEventListener('click', function(e) {
  if (e.target.classList.contains('modal-close')) {
    modal.hide();
  }
});
