//Toggle class active for hamburger menu
const nN = document.querySelector('.navbar-nav');
const pendingPanel = document.querySelector('.pending-orders');
const pendingButton = document.querySelector('#pending-orders-button');

const closePendingPanel = () => {
  if (!pendingPanel || !pendingPanel.classList.contains('active')) {
    return;
  }
  pendingPanel.classList.remove('active');
  window.dispatchEvent(new CustomEvent('rll:pending-close'));
};

const openPendingPanel = () => {
  if (!pendingPanel) {
    return;
  }
  pendingPanel.classList.add('active');
  window.dispatchEvent(new CustomEvent('rll:pending-open'));
};

// ketika hamburger menu diklik
document.querySelector('#hamburger-menu').onclick = (e) => {
  const willOpen = !nN.classList.contains('active');
  if (willOpen) {
    ShopCart.classList.remove('active');
    sf.classList.remove('active');
    closePendingPanel();
  }
  nN.classList.toggle('active');
  e.preventDefault();
};
//Toggle class active for search form
const sf = document.querySelector('.search-form');
const sb = document.querySelector('#search-box');
const searchButton = document.querySelector('#search-button');
//Toggle class active for shopping cart
const ShopCart = document.querySelector('.shopping-cart')
// ketika shopping cart item diklik
document.querySelector('#shopping-cart-button').onclick = (e) => {
  const willOpen = !ShopCart.classList.contains('active');
  if (willOpen) {
    sf.classList.remove('active');
    nN.classList.remove('active');
    closePendingPanel();
  }
  ShopCart.classList.toggle('active');
  e.preventDefault();
};
// ketika pending orders button diklik
if (pendingButton) {
  pendingButton.onclick = (e) => {
    const willOpen = !pendingPanel.classList.contains('active');
    if (willOpen) {
      ShopCart.classList.remove('active');
      sf.classList.remove('active');
      nN.classList.remove('active');
      openPendingPanel();
    } else {
      closePendingPanel();
    }
    e.preventDefault();
  };
}
// ketika search button diklik
searchButton.onclick = (e) => {
  const willOpen = !sf.classList.contains('active');
  if (willOpen) {
    ShopCart.classList.remove('active');
    nN.classList.remove('active');
    closePendingPanel();
    sb.focus();
  }
  sf.classList.toggle('active');
  e.preventDefault();
};
// klik di luar elemen untuk menutup
const hm = document.querySelector('#hamburger-menu');
const ShopCartButton = document.querySelector('#shopping-cart-button')
document.addEventListener('click', function(e) {
  if(!hm.contains(e.target) && !nN.contains(e.target)) {
    nN.classList.remove('active');
  }
  if(!searchButton.contains(e.target) && !sf.contains(e.target)) {
    sf.classList.remove('active');
  }
  if(!ShopCartButton.contains(e.target) && !ShopCart.contains(e.target)) {
    ShopCart.classList.remove('active');
  }
  const path = e.composedPath();
  if (
    pendingButton &&
    pendingPanel &&
    !path.includes(pendingButton) &&
    !path.includes(pendingPanel)
  ) {
    closePendingPanel();
  }
});
// Modal Box Item Detail
document.addEventListener('click', (e) => {
  const target = e.target;
  if (!(target instanceof Element)) return;

  const itemDetailButton = target.closest('.item-detail-button');
  if (itemDetailButton) {
    const modal = document.getElementById(
      `item-detail-modal${itemDetailButton.dataset.modalId}`
    );
    if (!modal) {
      console.error('Item detail modal was not found.');
      return;
    }

    modal.style.display = 'flex';
    e.preventDefault();
    return;
  }

  const closeButton = target.closest('.modal .close-icon');
  if (closeButton) {
    closeButton.closest('.modal').style.display = 'none';
    e.preventDefault();
    return;
  }

  if (target.classList.contains('modal')) {
    target.style.display = 'none';
  }
});