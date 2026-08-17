// MENU MOBILE

const menuToggle = document.getElementById("menuToggle");
const mobileMenu = document.getElementById("mobileMenu");

if (menuToggle && mobileMenu) {

  menuToggle.addEventListener("click", () => {

    mobileMenu.classList.toggle("active");

    const expanded =
      menuToggle.getAttribute("aria-expanded") === "true";

    menuToggle.setAttribute("aria-expanded", !expanded);
  });

}

// SCROLL REVEAL

const reveals = document.querySelectorAll(".reveal");

function revealOnScroll() {

  const triggerBottom = window.innerHeight * 0.9;

  reveals.forEach((element) => {

    const elementTop = element.getBoundingClientRect().top;

    if (elementTop < triggerBottom) {
      element.classList.add("active");
    }

  });

}

window.addEventListener("scroll", revealOnScroll);

revealOnScroll();

// FECHAR MENU AO CLICAR

document.querySelectorAll(".mobile-menu a").forEach((link) => {

  link.addEventListener("click", () => {
    mobileMenu.classList.remove("active");
  });

});