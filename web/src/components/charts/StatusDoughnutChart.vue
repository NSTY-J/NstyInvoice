<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch, computed } from 'vue'
import { Chart, DoughnutController, ArcElement, Tooltip, Legend } from 'chart.js'

Chart.register(DoughnutController, ArcElement, Tooltip, Legend)

const props = defineProps<{ counts: Record<string, number> }>()
const canvas = ref<HTMLCanvasElement | null>(null)
let chart: Chart | null = null

const palette: Record<string, string> = {
  paid:      '#4CAF7A',  // success green
  sent:      '#5C45A0',  // primary
  reminded:  '#E8A547',  // warning amber
  issued:    '#A99CD8',  // primary light
  cancelled: '#D45B5B',  // danger
  draft:     '#A7A0BA',  // neutral
}

const labels: Record<string, string> = {
  paid: 'Zaplaceno',
  sent: 'Odesláno',
  reminded: 'Upomenuto',
  issued: 'Vystaveno (neodesláno)',
  cancelled: 'Stornováno',
  draft: 'Koncept',
}

const slice = computed(() => {
  const order = ['paid', 'sent', 'reminded', 'issued', 'cancelled', 'draft']
  const labelArr: string[] = []
  const valueArr: number[] = []
  const colorArr: string[] = []
  for (const k of order) {
    const v = props.counts?.[k] ?? 0
    if (v > 0) {
      labelArr.push(labels[k] || k)
      valueArr.push(v)
      colorArr.push(palette[k] || '#A99CD8')
    }
  }
  return { labelArr, valueArr, colorArr }
})

function build() {
  if (!canvas.value) return
  if (chart) { chart.destroy(); chart = null }
  const { labelArr, valueArr, colorArr } = slice.value
  if (labelArr.length === 0) return
  const total = valueArr.reduce((s, v) => s + v, 0)
  chart = new Chart(canvas.value, {
    type: 'doughnut',
    data: {
      labels: labelArr,
      datasets: [{ data: valueArr, backgroundColor: colorArr, borderWidth: 1, borderColor: '#FFFFFF' }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } },
        tooltip: {
          callbacks: {
            label: (ctx) => {
              const v = ctx.parsed as number
              const pct = total > 0 ? ((v / total) * 100).toFixed(1) : '0'
              return ` ${ctx.label}: ${v} (${pct} %)`
            },
          },
        },
      },
      cutout: '55%',
    },
  })
}

onMounted(build)
onBeforeUnmount(() => chart?.destroy())
watch(() => props.counts, build, { deep: true })
</script>

<template>
  <div v-if="slice.labelArr.length === 0" class="text-sm text-neutral-400 text-center py-12">
    žádná data
  </div>
  <div v-else class="relative h-64">
    <canvas ref="canvas"></canvas>
  </div>
</template>
