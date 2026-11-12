const formatPrice = (price) =>
  `Rp ${new Intl.NumberFormat("id-ID").format(price)},-`;

// PERINGATAN: katalog ini harus selalu sinkron dengan php/products.php.
const menuItems = [
  {
    id: 1,
    name: "Paket Hemat Duo",
    img: "paket-hemat-duo.webp",
    price: 57000,
    originalPrice: 63000,
    fullStars: 5,
    partialStar: null,
  },
  {
    id: 2,
    name: "Paket Bakar Madu",
    img: "paket-bakar-madu.webp",
    price: 76000,
    originalPrice: 85000,
    fullStars: 4,
    partialStar: "star-8",
  },
  {
    id: 3,
    name: "Paket Pepes Nusantara",
    img: "paket-pepes-nusantara.webp",
    price: 69000,
    originalPrice: 77000,
    fullStars: 4,
    partialStar: "star-5",
  },
  {
    id: 4,
    name: "Paket Sop Ikan Hangat",
    img: "paket-sop-ikan-hangat.webp",
    price: 66000,
    originalPrice: 73000,
    fullStars: 4,
    partialStar: "star-6",
  },
  {
    id: 5,
    name: "Paket Goreng Sharing",
    img: "paket-goreng-sharing.webp",
    price: 128000,
    originalPrice: 142000,
    fullStars: 4,
    partialStar: "star-4",
  },
  {
    id: 6,
    name: "Paket Premium Kepiting",
    img: "paket-premium-kepiting.webp",
    price: 166000,
    originalPrice: 184000,
    fullStars: 4,
    partialStar: "star-7",
  },
];

const guardSnapScroll = () => {
  const top = window.scrollY;
  const root = document.documentElement;
  const previousBehavior = root.style.scrollBehavior;
  root.style.scrollBehavior = "auto";

  return () => {
    root.style.scrollBehavior = previousBehavior;
    window.scrollTo({ top, behavior: "instant" });
  };
};

const openSnapPayment = (token, callbacks = {}) =>
  new Promise((resolve, reject) => {
    if (!window.snap) {
      reject(new Error("Layanan pembayaran belum siap."));
      return;
    }

    let settled = false;
    const finish = (name, value) => {
      if (settled) {
        return;
      }
      settled = true;
      try {
        if (typeof callbacks[name] === "function") {
          callbacks[name](value);
        }
      } finally {
        resolve();
      }
    };

    try {
      window.snap.pay(token, {
        onSuccess: (result) => finish("onSuccess", result),
        onPending: (result) => finish("onPending", result),
        onError: (result) => finish("onError", result),
        onClose: () => finish("onClose"),
      });
    } catch (error) {
      reject(error);
    }
  });

document.addEventListener("alpine:init", () => {
  Alpine.store("catalog", {
    items: menuItems,
  });

  Alpine.data("menuBundling", () => ({
    items: menuItems,
    formatPrice(price) {
      return formatPrice(price);
    },
  }));

  Alpine.store("cart", {
    items: [],
    totalHarga: 0,
    qty: 0,
    formatPrice(price) {
      return formatPrice(price);
    },
    add(newItem) {
      // cek apakah ada barang yang sama pada cart
      const cartItem = this.items.find((item) => item.id === newItem.id);
      // kalau belum ada / cart masih kosong
      if (!cartItem) {
        this.items.push({
          ...newItem,
          qty: 1,
          totalHarga: newItem.price,
        });
        this.qty++;
        this.totalHarga += newItem.price;
      } else {
        // jika barang sudah ada, cek apakah barang sama atau beda dengan ada yang di cart
        this.items = this.items.map((item) => {
          // jika barang berbeda
          if (item.id != newItem.id) {
            return item;
          } else {
            // jika barang sama /sudah ada, tambah qty dan totalnya
            item.qty++;
            item.totalHarga = item.price * item.qty;
            this.qty++;
            this.totalHarga += item.price;
            return item;
          }
        });
      }
    },
    addById(id) {
      const item = Alpine.store("catalog").items.find(
        (catalogItem) => catalogItem.id === Number(id)
      );
      if (item) {
        this.add(item);
      }
    },
    remove(id) {
      // Cari item berdasarkan ID; jika tidak ada, keranjang tidak perlu diubah.
      const cartItem = this.items.find((item) => item.id === id);
      if (!cartItem) {
        return;
      }

      // Kurangi jumlah dan total harga setiap kali satu item dihapus.
      this.qty--;
      this.totalHarga -= cartItem.price;

      // Hapus item dari daftar jika jumlahnya tinggal satu.
      if (cartItem.qty === 1) {
        this.items = this.items.filter((item) => item.id !== id);
        return;
      }

      // Jika jumlahnya lebih dari satu, perbarui jumlah dan subtotal item.
      cartItem.qty--;
      cartItem.totalHarga = cartItem.price * cartItem.qty;
    },
    reset() {
      this.items = [];
      this.qty = 0;
      this.totalHarga = 0;
    },
  });

  Alpine.store("pendingOrders", {
    items: [],
    count: 0,
    loading: false,
    error: "",
    busy: false,
    cancelTargetId: null,
    cancelStep: 1,
    cancelReason: "",
    cancelText: "",
    lookupOrderId: "",
    lookupContact: "",
    lookupMessage: "",
    nowTick: Date.now(),
    clockTimer: null,
    pollTimer: null,
    expiryReloading: false,
    reasons: [
      { code: "wrong_items", label: "Salah memilih menu atau jumlah" },
      { code: "change_order", label: "Ingin mengubah pesanan" },
      { code: "change_payment", label: "Ingin mengganti metode pembayaran" },
      { code: "not_ordering", label: "Tidak jadi memesan" },
      { code: "payment_issue", label: "Kendala saat pembayaran" },
      { code: "other", label: "Lainnya" },
    ],
    formatPrice(price) {
      return formatPrice(price);
    },
    formatDate(value) {
      if (!value) {
        return "-";
      }
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) {
        return "-";
      }
      return new Intl.DateTimeFormat("id-ID", {
        dateStyle: "medium",
        timeStyle: "short",
      }).format(date);
    },
    notify(text, type = "pending") {
      window.dispatchEvent(
        new CustomEvent("rll:toast", { detail: { text, type } })
      );
    },
    async load(refresh = false) {
      this.loading = true;
      this.error = "";
      try {
        const url = refresh
          ? "php/orders-pending.php?refresh=1"
          : "php/orders-pending.php";
        const response = await fetch(url, {
          headers: { Accept: "application/json" },
          credentials: "same-origin",
          cache: "no-store",
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
          throw new Error(data.error || "Gagal memuat pesanan.");
        }
        this.items = Array.isArray(data.orders) ? data.orders : [];
        this.count = Number.isFinite(Number(data.count))
          ? Number(data.count)
          : this.items.length;
      } catch (error) {
        this.error =
          error instanceof Error
            ? error.message
            : "Gagal memuat pesanan. Coba lagi.";
      } finally {
        this.loading = false;
      }
    },
    startClock() {
      if (this.clockTimer) {
        return;
      }

      this.nowTick = Date.now();
      this.clockTimer = setInterval(() => {
        this.nowTick = Date.now();
        const hasExpired = this.items.some((order) => {
          const seconds = this.remainingSeconds(order);
          return seconds !== null && seconds <= 0;
        });

        if (hasExpired && !this.expiryReloading) {
          this.expiryReloading = true;
          this.load().finally(() => {
            this.expiryReloading = false;
          });
        }
      }, 1000);
    },
    stopClock() {
      if (this.clockTimer) {
        clearInterval(this.clockTimer);
        this.clockTimer = null;
      }
    },
    startPolling() {
      if (this.pollTimer) {
        return;
      }
      this.pollTimer = setInterval(() => {
        if (document.hidden || this.items.length === 0) {
          return;
        }
        if (!this.loading && !this.busy) {
          this.load(true);
        }
      }, 45000);
    },
    stopPolling() {
      if (this.pollTimer) {
        clearInterval(this.pollTimer);
        this.pollTimer = null;
      }
    },
    remainingSeconds(order) {
      if (!order || !order.expires_at) {
        return null;
      }
      const expires = Date.parse(order.expires_at);
      if (!Number.isFinite(expires)) {
        return null;
      }
      return Math.floor((expires - this.nowTick) / 1000);
    },
    remainingLabel(order) {
      const seconds = this.remainingSeconds(order);
      if (seconds === null) {
        return "-";
      }
      if (seconds <= 0) {
        return "Kedaluwarsa";
      }
      const minutes = Math.floor(seconds / 60);
      const rest = String(seconds % 60).padStart(2, "0");
      return `Sisa ${minutes}:${rest}`;
    },
    async resume(order) {
      if (this.busy) {
        return;
      }
      const seconds = this.remainingSeconds(order);
      if (seconds !== null && seconds <= 0) {
        this.notify(
          "Pesanan sudah kedaluwarsa. Silakan pesan ulang.",
          "error"
        );
        this.load();
        return;
      }
      if (!order || !order.snap_token) {
        this.notify(
          "Token pembayaran tidak tersedia. Batalkan lalu pesan ulang.",
          "error"
        );
        return;
      }
      if (!window.snap) {
        this.notify(
          "Layanan pembayaran belum siap. Muat ulang halaman lalu coba lagi.",
          "error"
        );
        return;
      }

      this.busy = true;
      const restoreScroll = guardSnapScroll();
      try {
        await openSnapPayment(order.snap_token, {
          onSuccess: () => {
            this.notify(
              `Pembayaran berhasil. Nomor pesanan: ${order.order_id}`,
              "success"
            );
            this.load(true);
          },
          onPending: () => {
            this.notify(
              `Menunggu pembayaran. Nomor pesanan: ${order.order_id}`,
              "pending"
            );
            this.load(true);
          },
          onError: () => {
            this.notify(
              "Pembayaran gagal diproses. Coba lagi atau batalkan pesanan.",
              "error"
            );
            this.load(true);
          },
          onClose: () => {
            this.load(true);
          },
        });
      } catch (error) {
        this.notify("Gagal membuka pembayaran. Coba lagi.", "error");
      } finally {
        restoreScroll();
        this.busy = false;
      }
    },
    openCancel(order) {
      this.cancelTargetId = order.order_id;
      this.cancelStep = 1;
      this.cancelReason = "";
      this.cancelText = "";
    },
    closeCancel() {
      this.cancelTargetId = null;
      this.cancelStep = 1;
      this.cancelReason = "";
      this.cancelText = "";
    },
    nextCancelStep() {
      if (!this.cancelReason) {
        this.notify("Pilih alasan pembatalan.", "error");
        return;
      }
      if (this.cancelReason === "other") {
        const text = this.cancelText.trim();
        if (text.length < 5 || text.length > 200) {
          this.notify(
            'Alasan "Lainnya" wajib diisi 5-200 karakter.',
            "error"
          );
          return;
        }
      }
      this.cancelStep = 2;
    },
    cancelSummary() {
      const reason = this.reasons.find(
        (item) => item.code === this.cancelReason
      );
      let summary = reason ? reason.label : "";
      if (this.cancelReason === "other" && this.cancelText.trim()) {
        summary += `: ${this.cancelText.trim()}`;
      }
      return summary;
    },
    async submitCancel(order) {
      if (this.busy) {
        return;
      }

      this.busy = true;
      try {
        const response = await fetch("php/cancel-order.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest",
          },
          credentials: "same-origin",
          body: JSON.stringify({
            order_id: order.order_id,
            reason_code: this.cancelReason,
            reason_text: this.cancelText.trim(),
          }),
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
          if (response.status === 409) {
            this.notify(
              data.error || "Pesanan tidak dapat dibatalkan.",
              "pending"
            );
            this.closeCancel();
            await this.load();
            return;
          }
          throw new Error(data.error || "Gagal membatalkan pesanan.");
        }

        this.notify("Pesanan dibatalkan.", "success");
        this.closeCancel();
        await this.load();
      } catch (error) {
        this.notify(
          error instanceof Error
            ? error.message
            : "Gagal membatalkan pesanan. Coba lagi.",
          "error"
        );
      } finally {
        this.busy = false;
      }
    },
    async lookup() {
      if (this.busy) {
        return;
      }

      const orderId = this.lookupOrderId.trim();
      const contact = this.lookupContact.trim();

      if (!orderId || !contact) {
        this.lookupMessage = "Isi kode pesanan dan kontak.";
        return;
      }

      this.busy = true;
      this.lookupMessage = "";
      try {
        const response = await fetch("php/order-lookup.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest",
          },
          credentials: "same-origin",
          body: JSON.stringify({ order_id: orderId, contact }),
        });
        const data = await response.json().catch(() => ({}));

        if (!response.ok) {
          this.lookupMessage =
            data.error || "Pesanan tidak ditemukan.";
          return;
        }

        this.notify(
          "Pesanan berhasil ditautkan ke perangkat ini.",
          "success"
        );
        this.lookupOrderId = "";
        this.lookupContact = "";
        await this.load();
      } catch (error) {
        this.lookupMessage = "Terjadi kesalahan. Coba lagi.";
      } finally {
        this.busy = false;
      }
    },
  });
});

// form validation + checkout
document.addEventListener("DOMContentLoaded", () => {
  const checkOutButton = document.querySelector(".checkout-button");
  const form = document.getElementById("checkOutForm");
  const cartPanel = document.querySelector(".shopping-cart");
  const message = document.getElementById("checkout-message");
  const messageText = document.getElementById("checkout-message-text");
  const receiptLink = document.getElementById("receipt-link");
  const inputs = form.querySelectorAll("#checkoutName, #checkoutEmail, #checkoutPhone");

  let isSubmitting = false;
  let messageTimer;
  let receiptCheckTimer;

  const updateCheckoutButton = () => {
    if (isSubmitting) {
      return;
    }

    const allFieldsFilled = [...inputs].every((input) => input.value.trim() !== "");
    checkOutButton.disabled = !allFieldsFilled;
    checkOutButton.classList.toggle("disabled", !allFieldsFilled);
  };

  const hideReceiptLink = () => {
    clearTimeout(receiptCheckTimer);
    receiptLink.hidden = true;
    receiptLink.removeAttribute("href");
  };

  const clearMessage = () => {
    clearTimeout(messageTimer);
    hideReceiptLink();
    messageText.textContent = "";
    message.className = "checkout-message";
    message.hidden = true;
  };

  const showMessage = (text, type) => {
    clearTimeout(messageTimer);
    hideReceiptLink();
    messageText.textContent = text;
    message.className = `checkout-message ${type}`;
    message.hidden = false;

    if (type !== "error") {
      messageTimer = setTimeout(clearMessage, 8000);
    }
  };

  const checkReceiptEligibility = async (orderId, attempt = 0) => {
    try {
      const response = await fetch(
        `php/receipt.php?order_id=${encodeURIComponent(orderId)}&check=1`,
        { cache: "no-store", headers: { Accept: "application/json" } }
      );
      const data = response.ok ? await response.json().catch(() => null) : null;

      if (data && data.eligible === true) {
        receiptLink.href = `php/receipt.php?order_id=${encodeURIComponent(orderId)}`;
        receiptLink.hidden = false;
        return;
      }
    } catch {
      // Status struk diperiksa lagi selama pesan sukses masih tampil.
    }

    if (attempt < 1 && !message.hidden) {
      receiptCheckTimer = setTimeout(
        () => checkReceiptEligibility(orderId, attempt + 1),
        4000
      );
    }
  };

  window.addEventListener("rll:toast", (event) => {
    const detail = event.detail || {};
    showMessage(String(detail.text || ""), detail.type || "pending");
  });

  window.addEventListener("rll:pending-open", () => {
    const store = Alpine.store("pendingOrders");
    store.load(true);
    store.startClock();
    store.startPolling();
  });

  window.addEventListener("rll:pending-close", () => {
    const store = Alpine.store("pendingOrders");
    store.stopClock();
    store.stopPolling();
  });

  const setProcessing = (processing) => {
    isSubmitting = processing;
    checkOutButton.disabled = processing;
    checkOutButton.classList.toggle("disabled", processing);
    checkOutButton.textContent = processing ? "Memproses..." : "Checkout";

    if (!processing) {
      updateCheckoutButton();
    }
  };

  const closeCartPanel = () => {
    cartPanel.classList.remove("active");
  };

  const resetCheckout = () => {
    Alpine.store("cart").reset();
    form.reset();
    updateCheckoutButton();
  };

  const checkout = async (customer) => {
    if (!window.snap) {
      throw new Error(
        "Layanan pembayaran belum siap. Muat ulang halaman lalu coba lagi."
      );
    }

    const cart = Alpine.store("cart");
    const payload = {
      customer,
      items: cart.items.map((item) => ({ id: item.id, qty: item.qty })),
    };

    const response = await fetch("php/place-order.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify(payload),
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok || !data.token) {
      throw new Error(data.error || "Gagal membuat transaksi pembayaran.");
    }

    const orderId = data.order_id;
    const restoreScroll = guardSnapScroll();

    try {
      await openSnapPayment(data.token, {
        onSuccess: () => {
          setProcessing(false);
          closeCartPanel();
          resetCheckout();
          showMessage(
            `Terima kasih! Pembayaran berhasil. Nomor pesanan: ${orderId}`,
            "success"
          );
          checkReceiptEligibility(orderId);
          Alpine.store("pendingOrders").load(true);
        },
        onPending: () => {
          setProcessing(false);
          closeCartPanel();
          resetCheckout();
          showMessage(
            `Menunggu pembayaran. Nomor pesanan: ${orderId}`,
            "pending"
          );
          Alpine.store("pendingOrders").load(true);
        },
        onError: () => {
          setProcessing(false);
          showMessage("Pembayaran gagal diproses. Silakan coba lagi.", "error");
          Alpine.store("pendingOrders").load(true);
        },
        onClose: () => {
          setProcessing(false);
          showMessage(
            "Pembayaran dibatalkan. Keranjang Anda masih tersimpan.",
            "pending"
          );
          Alpine.store("pendingOrders").load(true);
        },
      });
    } finally {
      restoreScroll();
    }
  };

  form.addEventListener("input", updateCheckoutButton);
  form.addEventListener("submit", async (event) => {
    event.preventDefault();

    if (isSubmitting) {
      return;
    }

    const customer = {
      name: form.elements.name.value.trim(),
      email: form.elements.email.value.trim(),
      phone: form.elements.phone.value.trim(),
    };

    if (!customer.name || !customer.email || !customer.phone) {
      updateCheckoutButton();
      return;
    }

    clearMessage();
    setProcessing(true);

    try {
      await checkout(customer);
    } catch (error) {
      console.error("Checkout gagal:", error);
      setProcessing(false);
      showMessage(
        error instanceof Error
          ? error.message
          : "Terjadi kesalahan. Silakan coba lagi.",
        "error"
      );
    }
  });

  updateCheckoutButton();
  Alpine.store("pendingOrders").load();
});
