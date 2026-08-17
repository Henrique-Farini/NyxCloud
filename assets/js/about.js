
const members = document.querySelectorAll(".member-card");

members.forEach(card => {

  const button = card.querySelector(".toggle-btn");

  button.addEventListener("click", () => {

    const active = card.classList.contains("active");

    members.forEach(item => {
      item.classList.remove("active");
      item.querySelector(".toggle-btn").textContent = "+";
    });

    if(!active){
      card.classList.add("active");
      button.textContent = "−";
    }

  });

});
