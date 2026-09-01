(function () {
  "use strict";

  const form = document.getElementById("loginForm");
  const message = document.getElementById("loginMessage");

  if (!form || !message) return;

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    message.textContent = "";

    const button = form.querySelector("button[type=submit]");
    if (button) button.disabled = true;

    try {
      const response = await fetch(form.action, {
        method: "POST",
        headers: { "Accept": "application/json" },
        body: new FormData(form)
      });
      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || "Nao foi possivel fazer login.");
      }

      localStorage.removeItem("access_token");
      sessionStorage.removeItem("access_token");

      window.location.href = "painel.php";
    } catch (error) {
      message.textContent = error.message;
      message.style.color = "#ef4444";
    } finally {
      if (button) button.disabled = false;
    }
  });
})();
