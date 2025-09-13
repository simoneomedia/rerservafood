(function(){
  if(!window.WCOF_ANALYTICS) return;
  const data = window.WCOF_ANALYTICS.topProducts || [];
  const ctx = document.getElementById('wcof-analytics-chart');
  if(!ctx) return;
  const labels = data.map(p=>p.name);
  const quantities = data.map(p=>p.qty);
  new Chart(ctx, {
    type: 'bar',
    data: { labels: labels, datasets:[{ data: quantities, backgroundColor: '#4ade80' }] },
    options: { responsive: true, plugins:{ legend:{ display:false } } }
  });
})();
