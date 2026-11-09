(function () {
  'use strict';
  var card = document.getElementById('verification-id');
  if (!card) return;
  var front = card.querySelector('.verification-id-front');
  var back = card.querySelector('.verification-id-back');
  var name = card.getAttribute('aria-label').replace('Show back of ID.', '');
  // Native button keyboard behavior handles Enter and Space as well as clicks.
  var flipControl = document.getElementById('id-flip-control');
  var sideLabel = document.getElementById('id-visible-side');
  function flipCard() {
    var flipped = card.classList.toggle('is-flipped');
    card.setAttribute('aria-pressed', String(flipped));
    card.setAttribute('aria-label', name + 'Show ' + (flipped ? 'front' : 'back') + ' of ID.');
    front.setAttribute('aria-hidden', String(flipped));
    back.setAttribute('aria-hidden', String(!flipped));
    front.inert = flipped;
    back.inert = !flipped;
    if (flipControl) {
      flipControl.setAttribute('aria-pressed', String(flipped));
      flipControl.querySelector('span').textContent = 'View ' + (flipped ? 'front' : 'back') + ' of ID';
    }
    if (sideLabel) sideLabel.textContent = flipped ? 'Back side' : 'Front side';
  }
  card.addEventListener('click', flipCard);
  if (flipControl) flipControl.addEventListener('click', flipCard);
})();
