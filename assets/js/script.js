/* ============================================
   NYXCLOUD — script.js
   Clean, minimal, production-ready JS
   ============================================ */

(function () {
  "use strict";

  /* ============================================
     MOBILE MENU
  ============================================ */

  const header = document.getElementById("header");
  const menuToggle = document.getElementById("menuToggle");
  const mobileMenu = document.getElementById("mobileMenu");

  if (menuToggle && mobileMenu) {
    menuToggle.addEventListener("click", () => {
      const isOpen = mobileMenu.classList.toggle("active");
      menuToggle.setAttribute("aria-expanded", isOpen);
      mobileMenu.setAttribute("aria-hidden", !isOpen);
    });

    // Close on link click
    mobileMenu.querySelectorAll("a").forEach(link => {
      link.addEventListener("click", () => {
        mobileMenu.classList.remove("active");
        menuToggle.setAttribute("aria-expanded", "false");
        mobileMenu.setAttribute("aria-hidden", "true");
      });
    });

    // Close on outside click
    document.addEventListener("click", (e) => {
      if (!header.contains(e.target) && mobileMenu.classList.contains("active")) {
        mobileMenu.classList.remove("active");
        menuToggle.setAttribute("aria-expanded", "false");
        mobileMenu.setAttribute("aria-hidden", "true");
      }
    });
  }

  /* ============================================
     TESTIMONIAL SLIDER
  ============================================ */

  const testimonials = document.querySelectorAll(".testimonial");
  const dots = document.querySelectorAll(".dot");
  const prevBtn = document.getElementById("prevTestimonial");
  const nextBtn = document.getElementById("nextTestimonial");

  if (testimonials.length > 0) {
    let current = 0;
    let autoSlideTimer;

    function showSlide(index) {
      testimonials.forEach(t => t.classList.remove("active"));
      dots.forEach(d => d.classList.remove("active"));

      testimonials[index].classList.add("active");
      if (dots[index]) dots[index].classList.add("active");
    }

    function nextSlide() {
      current = (current + 1) % testimonials.length;
      showSlide(current);
    }

    function prevSlide() {
      current = (current - 1 + testimonials.length) % testimonials.length;
      showSlide(current);
    }

    function resetAutoSlide() {
      clearInterval(autoSlideTimer);
      autoSlideTimer = setInterval(nextSlide, 7000);
    }

    if (nextBtn) {
      nextBtn.addEventListener("click", () => {
        nextSlide();
        resetAutoSlide();
      });
    }

    if (prevBtn) {
      prevBtn.addEventListener("click", () => {
        prevSlide();
        resetAutoSlide();
      });
    }

    dots.forEach(dot => {
      dot.addEventListener("click", () => {
        current = parseInt(dot.dataset.index, 10);
        showSlide(current);
        resetAutoSlide();
      });
    });

    autoSlideTimer = setInterval(nextSlide, 7000);
  }

  /* ============================================
     SCROLL REVEAL
  ============================================ */

  const revealEls = document.querySelectorAll(".reveal");

  if (revealEls.length > 0 && "IntersectionObserver" in window) {
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            entry.target.classList.add("active");
            observer.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.1, rootMargin: "0px 0px -40px 0px" }
    );

    revealEls.forEach(el => observer.observe(el));
  } else {
    // Fallback for older browsers
    revealEls.forEach(el => el.classList.add("active"));
  }

  /* ============================================
   CONTACT FORM
============================================ */
const form = document.getElementById("contactForm");
const formMessage = document.getElementById("formMessage");

if (form && formMessage) {

  form.addEventListener("submit", async (e) => {

    e.preventDefault();

    const submitBtn = form.querySelector('button[type="submit"]');

    formMessage.textContent = "";

    if (submitBtn) {
      submitBtn.disabled = true;
      submitBtn.textContent = "Enviando...";
    }

    try {

      const response = await fetch("enviar-contato.php", {
        method: "POST",
        body: new FormData(form)
      });

      const data = await response.json();

      if (data.success) {

        formMessage.textContent = data.message;
        formMessage.style.color = "#16a34a";

        form.reset();

      } else {

        formMessage.textContent = data.message;
        formMessage.style.color = "#ef4444";

      }

    } catch (error) {

      formMessage.textContent =
        "Erro ao conectar com o servidor.";

      formMessage.style.color = "#ef4444";
    }

    if (submitBtn) {
      submitBtn.disabled = false;
      submitBtn.textContent = "Enviar mensagem";
    }

  });

}

  /* ============================================
     FOOTER NEWSLETTER
  ============================================ */

  const newsletterForm = document.querySelector(".footer-col form, .footer-newsletter form");

  if (newsletterForm) {
    newsletterForm.addEventListener("submit", (e) => {
      e.preventDefault();
      const input = newsletterForm.querySelector("input");
      if (input && input.value.trim()) {
        input.value = "";
        input.placeholder = "Inscrição confirmada!";
        setTimeout(() => {
          input.placeholder = "Seu e-mail";
        }, 3000);
      }
    });
  }



  /* ============================================
     PLATFORM TABS
  ============================================ */

  const platformTabs = document.querySelectorAll(".pnav");
  const platformContent = document.getElementById("platformContent");

  const platformViews = {
    overview: `
      <div class="preview-kpis">
        <div class="kpi-card">
          <span>Servidores</span>
          <strong>48</strong>
          <em class="kpi-status ok">Todos online</em>
        </div>
        <div class="kpi-card">
          <span>Tarefas ativas</span>
          <strong>12</strong>
          <em class="kpi-status">Em execução</em>
        </div>
        <div class="kpi-card">
          <span>Alertas</span>
          <strong>0</strong>
          <em class="kpi-status ok">Nenhum crítico</em>
        </div>
      </div>
    `,

    backups: `
      <div class="preview-kpis">
        <div class="kpi-card">
          <span>Backups hoje</span>
          <strong>128</strong>
          <em class="kpi-status ok">98% sucesso</em>
        </div>

        <div class="kpi-card">
          <span>Armazenamento</span>
          <strong>8.2TB</strong>
          <em class="kpi-status">Utilizado</em>
        </div>

        <div class="kpi-card">
          <span>Retenção</span>
          <strong>30d</strong>
          <em class="kpi-status ok">Ativa</em>
        </div>
      </div>
    `,

    alerts: `
      <div class="preview-kpis">
        <div class="kpi-card">
          <span>Alertas ativos</span>
          <strong>3</strong>
          <em class="kpi-status">Monitorando</em>
        </div>

        <div class="kpi-card">
          <span>Incidentes</span>
          <strong>1</strong>
          <em class="kpi-status">Baixa prioridade</em>
        </div>

        <div class="kpi-card">
          <span>Status</span>
          <strong>OK</strong>
          <em class="kpi-status ok">Sistema estável</em>
        </div>
      </div>
    `,

    reports: `
      <div class="preview-kpis">
        <div class="kpi-card">
          <span>Relatórios</span>
          <strong>24</strong>
          <em class="kpi-status ok">Gerados</em>
        </div>

        <div class="kpi-card">
          <span>Exports</span>
          <strong>12</strong>
          <em class="kpi-status">PDF/CSV</em>
        </div>

        <div class="kpi-card">
          <span>Atualização</span>
          <strong>Live</strong>
          <em class="kpi-status ok">Sincronizado</em>
        </div>
      </div>
    `
  };

  if (platformTabs.length && platformContent) {

    platformTabs.forEach(tab => {

      tab.addEventListener("click", () => {

        platformTabs.forEach(item => item.classList.remove("active"));
        tab.classList.add("active");

        const currentTab = tab.dataset.tab;

        platformContent.style.opacity = "0";

        setTimeout(() => {
          platformContent.innerHTML = platformViews[currentTab];
          platformContent.style.opacity = "1";
        }, 180);

      });

    });

  }


})();

/* ============================================
   PLANOS -> PREENCHER FORMULÁRIO
============================================ */

const planButtons = document.querySelectorAll(".plan-btn");
const messageField = document.getElementById("message");

const planMessages = {
  Starter: `Olá NyxCloud,

Tenho interesse no plano Starter.

Gostaria de receber mais informações sobre implantação, suporte e contratação.`,

  Business: `Olá NyxCloud,

Tenho interesse no plano Business.

Gostaria de receber uma proposta comercial e entender melhor os recursos disponíveis para minha empresa.`,

  Enterprise: `Olá NyxCloud,

Tenho interesse em uma solução Enterprise.

Gostaria de falar com um especialista para avaliar as necessidades da minha infraestrutura.`
};

planButtons.forEach(btn => {

  btn.addEventListener("click", () => {

    const plan = btn.dataset.plan;

    if (messageField && planMessages[plan]) {
      messageField.value = planMessages[plan];

      // efeito visual
      messageField.classList.add("highlight");

      setTimeout(() => {
        messageField.classList.remove("highlight");
      }, 1200);
    }

  });

});

document.addEventListener("DOMContentLoaded", () => {

    const banner = document.getElementById("cookieBanner");

    const accept = document.getElementById("acceptCookies");

    const reject = document.getElementById("rejectCookies");

    const settings =
        document.getElementById("cookieSettings");

    const modal =
        document.getElementById("cookieModal");

    const closeModal =
        document.getElementById("closeCookieModal");

    const saveSettings =
        document.getElementById("saveCookieSettings");

    const allowAll =
        document.getElementById("allowAllCookies");

    if (!banner || !accept || !reject || !settings || !modal || !closeModal || !saveSettings || !allowAll) {
        return;
    }


    // Mostrar banner

    const cookieChoice =
        localStorage.getItem("cookieConsent");

    if(cookieChoice === null){

        banner.style.display = "block";

    }


    // Abrir modal

    settings.addEventListener("click", () => {

        modal.style.display = "flex";

    });


    // Fechar modal

    closeModal.addEventListener("click", () => {

        modal.style.display = "none";

    });


    // Aceitar

    accept.addEventListener("click", () => {

        localStorage.setItem(
            "cookieConsent",
            "accepted"
        );

        banner.style.display = "none";

    });


    // Recusar

    reject.addEventListener("click", () => {

        localStorage.setItem(
            "cookieConsent",
            "rejected"
        );

        banner.style.display = "none";

    });


    // Salvar preferências

    saveSettings.addEventListener("click", () => {

        const preferences = {

            functional:
            document.getElementById(
                "functionalCookies"
            ).checked,

            performance:
            document.getElementById(
                "performanceCookies"
            ).checked,

            marketing:
            document.getElementById(
                "marketingCookies"
            ).checked

        };

        localStorage.setItem(
            "cookiePreferences",
            JSON.stringify(preferences)
        );

        localStorage.setItem(
            "cookieConsent",
            "custom"
        );

        modal.style.display = "none";

        banner.style.display = "none";

    });


    // Permitir todos

    allowAll.addEventListener("click", () => {

        document.getElementById(
            "functionalCookies"
        ).checked = true;

        document.getElementById(
            "performanceCookies"
        ).checked = true;

        document.getElementById(
            "marketingCookies"
        ).checked = true;

        localStorage.setItem(
            "cookieConsent",
            "accepted"
        );

        modal.style.display = "none";

        banner.style.display = "none";

    });

});
