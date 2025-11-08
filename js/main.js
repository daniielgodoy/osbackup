document.addEventListener("DOMContentLoaded", () => {
  const ctx1 = document.getElementById("chartServicos");
  const ctx2 = document.getElementById("chartFinanceiro");

  new Chart(ctx1, {
    type: "bar",
    data: {
      labels: ["Pendente", "Concluída", "Em Andamento", "Garantia"],
      datasets: [{
        data: [12, 25, 18, 4],
        backgroundColor: ["#ff6b6b", "#74ff90", "#6baaff", "#ffcc66"]
      }]
    },
    options: {
      plugins: { legend: { display: false } },
      scales: { y: { ticks: { color: "#ccc" } }, x: { ticks: { color: "#ccc" } } }
    }
  });

  new Chart(ctx2, {
    type: "doughnut",
    data: {
      labels: ["Despesas", "Receitas", "Balanço"],
      datasets: [{
        data: [3200, 5800, 2600],
        backgroundColor: ["#ff6b6b", "#74ff90", "#6baaff"]
      }]
    },
    options: { plugins: { legend: { labels: { color: "#ccc" } } } }
  });
});
