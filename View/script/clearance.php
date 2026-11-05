<?php
$data = [
    'Cleared'   => 40,
    'Not yet Cleared' => 35,
    'Pending'  => 15
];

$labels = json_encode(array_keys($data));
$values = json_encode(array_values($data));
?>

<script>
new Chart(document.getElementById('pie'), {
      type: 'pie',
      data: {
        labels: <?= $labels ?>,
        datasets: [{
          data: <?= $values ?>,
          backgroundColor: ['#7700ff', '#cf00fd', '#00a4fd', '#1eff00']
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          title: {
            display: true,
            text: 'Clearance Status',
            color: '#f8f1f1',
            font: { size: 30, weight: 'bold', family: 'Arial' }
          },
          legend: {
            position: 'bottom',
            align: 'start',
            labels: {
              color: '#ffffff',                 // legend text color
              font: { size: 20, family: 'Arial' },
              padding: 40,
              usePointStyle: true            // round bullets instead of boxes
            }
          },
          tooltip: {
            backgroundColor: '#222',         // hover box background
            titleColor: '#fff',
            bodyColor: '#ffd166',
            bodyFont: { size: 14 },
            padding: 10
          }
        }
      }
    });
</script>
