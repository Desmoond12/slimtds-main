import Alpine from 'alpinejs'
import * as echarts from 'echarts/core'
import { LineChart } from 'echarts/charts'
import { GridComponent, LegendComponent, TitleComponent, TooltipComponent } from 'echarts/components'
import { CanvasRenderer } from 'echarts/renderers'

echarts.use([LineChart, GridComponent, LegendComponent, TitleComponent, TooltipComponent, CanvasRenderer])

window.Alpine = Alpine

Alpine.data('flowBuilder', (opts) => ({
  filters: opts.initialFilters && opts.initialFilters.length ? opts.initialFilters : [],
  targetOffers: opts.initialTargetOffers || [],
  targetType: opts.initialTargetType || 'offers',
  schemaId: opts.initialSchemaId || 2,
  // Coerce to plain object: PHP empty associative array serializes as JS array []
  // and JSON.stringify drops non-numeric props, so {body: "..."} would be lost.
  schemaConfig: (opts.initialSchemaConfig && !Array.isArray(opts.initialSchemaConfig))
    ? { ...opts.initialSchemaConfig }
    : {},
  availableOffers: opts.availableOffers || [],

  init() {
    // When the user picks Offer(s) as target type, surface an empty offer-row
    // immediately so the dropdown is visible without an extra "+ add offer" click.
    this.$watch('targetType', (next) => {
      if (next === 'offers' && this.targetOffers.length === 0) {
        this.addTarget()
      }
    })
    // Same on initial mount when the form opens with target_type=offers and no rows.
    if (this.targetType === 'offers' && this.targetOffers.length === 0) {
      this.addTarget()
    }
  },

  get weightsSum() {
    return this.targetOffers.reduce((s, t) => s + (parseInt(t.weight, 10) || 0), 0)
  },

  addGroup() {
    this.filters.push([{ field: 'country', op: 'eq', value: '' }])
  },
  removeGroup(gi) {
    this.filters.splice(gi, 1)
  },
  addCondition(gi) {
    this.filters[gi].push({ field: 'country', op: 'eq', value: '' })
  },
  removeCondition(gi, ci) {
    this.filters[gi].splice(ci, 1)
    if (this.filters[gi].length === 0) {
      this.filters.splice(gi, 1)
    }
  },
  // `in`/`not_in` take a comma-separated list of values, so value inputs that
  // cap length for a single token (country: 2 chars) must lift the cap.
  isListOp(op) {
    return op === 'in' || op === 'not_in'
  },
  addTarget() {
    this.targetOffers.push({ offer_id: '', weight: 100 })
  },
  removeTarget(idx) {
    this.targetOffers.splice(idx, 1)
  },

  syncHidden() {
    this.$refs.filtersField.value = JSON.stringify(this.filters)
    this.$refs.targetOffersField.value = JSON.stringify(this.targetOffers)
    this.$refs.schemaConfigField.value = JSON.stringify(this.schemaConfig)
  },
}))

Alpine.data('statsChart', (data) => ({
  chart: null,
  init() {
    this.chart = echarts.init(this.$el)
    this.chart.setOption({
      grid: { left: 50, right: 20, top: 30, bottom: 30, containLabel: true },
      xAxis: { type: 'time', axisLabel: { color: '#6b6457' } },
      yAxis: { type: 'value', axisLabel: { color: '#6b6457' } },
      legend: { textStyle: { color: '#3f3a32' }, top: 0 },
      tooltip: { trigger: 'axis' },
      series: [
        { name: 'Clicks', type: 'line', smooth: true, data: data.points.map(p => [p.hour, p.clicks]), color: '#2f6bdd' },
        { name: 'Unique', type: 'line', smooth: true, data: data.points.map(p => [p.hour, p.uniq]),   color: '#0d6e6e' },
        { name: 'Bots',   type: 'line', smooth: true, data: data.points.map(p => [p.hour, p.bot]),    color: '#a8a399' },
      ],
    })
    window.addEventListener('resize', () => this.chart?.resize())
  },
}))

// Compact 48-hour timeline used at the top of /admin/clicks.
// Three series (clicks/unique/bots) over fixed 48 hourly buckets ending at
// the current hour. Soft area fill on the primary series so the chart reads
// as a "summary stripe" rather than a full analytics dashboard.
Alpine.data('clicksTimeline', (data) => ({
  chart: null,
  init() {
    this.chart = echarts.init(this.$el)
    this.chart.setOption({
      grid: { left: 8, right: 12, top: 28, bottom: 22, containLabel: true },
      xAxis: {
        type: 'time',
        axisLabel: { color: '#6b6457', fontSize: 10, hideOverlap: true },
        axisLine:  { lineStyle: { color: '#d6d2c8' } },
        axisTick:  { show: false },
        splitLine: { show: false },
      },
      yAxis: {
        type: 'value',
        axisLabel: { color: '#a8a399', fontSize: 10 },
        axisLine:  { show: false },
        axisTick:  { show: false },
        splitLine: { lineStyle: { color: '#ece8df', type: 'dashed' } },
      },
      legend: { textStyle: { color: '#6b6457', fontSize: 11 }, top: 0, right: 8, itemHeight: 8, itemWidth: 14 },
      tooltip: { trigger: 'axis', axisPointer: { type: 'line', lineStyle: { color: '#2f6bdd', width: 1, type: 'dashed' } } },
      series: [
        {
          name: 'Clicks', type: 'line', smooth: true, showSymbol: false,
          data: data.points.map(p => [p.hour, p.clicks]),
          lineStyle: { width: 2, color: '#2f6bdd' },
          itemStyle: { color: '#2f6bdd' },
          areaStyle: { color: 'rgba(47, 107, 221, 0.14)' },
        },
        {
          name: 'Unique', type: 'line', smooth: true, showSymbol: false,
          data: data.points.map(p => [p.hour, p.uniq]),
          lineStyle: { width: 1.5, color: '#0d6e6e', type: 'dashed' },
          itemStyle: { color: '#0d6e6e' },
        },
        {
          name: 'Bots', type: 'line', smooth: true, showSymbol: false,
          data: data.points.map(p => [p.hour, p.bot]),
          lineStyle: { width: 1, color: '#a8a399' },
          itemStyle: { color: '#a8a399' },
        },
      ],
    })
    window.addEventListener('resize', () => this.chart?.resize())
  },
}))

// Compact 48-hour timeline for /admin/pixel — events + unique visitors.
Alpine.data('pixelTimeline', (data) => ({
  chart: null,
  init() {
    this.chart = echarts.init(this.$el)
    this.chart.setOption({
      grid: { left: 8, right: 12, top: 28, bottom: 22, containLabel: true },
      xAxis: {
        type: 'time',
        axisLabel: { color: '#6b6457', fontSize: 10, hideOverlap: true },
        axisLine:  { lineStyle: { color: '#d6d2c8' } },
        axisTick:  { show: false },
        splitLine: { show: false },
      },
      yAxis: {
        type: 'value',
        axisLabel: { color: '#a8a399', fontSize: 10 },
        axisLine:  { show: false },
        axisTick:  { show: false },
        splitLine: { lineStyle: { color: '#ece8df', type: 'dashed' } },
      },
      legend: { textStyle: { color: '#6b6457', fontSize: 11 }, top: 0, right: 8, itemHeight: 8, itemWidth: 14 },
      tooltip: { trigger: 'axis', axisPointer: { type: 'line', lineStyle: { color: '#0d6e6e', width: 1, type: 'dashed' } } },
      series: [
        {
          name: 'Events', type: 'line', smooth: true, showSymbol: false,
          data: data.points.map(p => [p.hour, p.events]),
          lineStyle: { width: 2, color: '#0d6e6e' },
          itemStyle: { color: '#0d6e6e' },
          areaStyle: { color: 'rgba(13, 110, 110, 0.14)' },
        },
        {
          name: 'Unique', type: 'line', smooth: true, showSymbol: false,
          data: data.points.map(p => [p.hour, p.uniq]),
          lineStyle: { width: 1.5, color: '#2f6bdd', type: 'dashed' },
          itemStyle: { color: '#2f6bdd' },
        },
      ],
    })
    window.addEventListener('resize', () => this.chart?.resize())
  },
}))

// Status-history drawer on /admin/conversions — one shared drawer, fetched
// lazily per conversion id on open() rather than embedding all rows' history
// server-side (which would be an N+1 query per page load).
Alpine.data('conversionHistoryPanel', () => ({
  openId: null,
  loading: false,
  error: false,
  rows: [],

  open(id) {
    this.openId = id
    this.loading = true
    this.error = false
    this.rows = []
    fetch('/admin/conversions/' + encodeURIComponent(id) + '/history', {
      headers: { Accept: 'application/json' },
    })
      .then((r) => {
        if (!r.ok) throw new Error('bad status')
        return r.json()
      })
      .then((data) => {
        this.rows = data.history || []
      })
      .catch(() => {
        this.error = true
      })
      .finally(() => {
        this.loading = false
      })
  },
  close() {
    this.openId = null
  },
}))

// Tools → Postback log — "fire a test postback" widget. Deliberately fetches
// the REAL /postback endpoint (not a simulated in-process call) so a test
// hit exercises the exact same code path — and lands in the exact same
// core.postback_requests log — as a genuine partner hit would.
Alpine.data('postbackTester', (offers) => ({
  offers: offers || [],
  offerId: '',
  subid: '',
  status: 'approved',
  payout: '10',
  externalId: '',
  firing: false,
  result: '',

  fire() {
    const offer = this.offers.find((o) => o.id === this.offerId)
    if (!offer) return
    this.firing = true
    this.result = ''
    const params = new URLSearchParams({
      token: offer.token,
      status: this.status,
      payout: this.payout,
    })
    if (this.subid) params.set('subid', this.subid)
    if (this.externalId) params.set('external_id', this.externalId)

    fetch('/postback?' + params.toString())
      .then((r) => r.json().then((body) => ({ httpStatus: r.status, body })))
      .then(({ httpStatus, body }) => {
        this.result = 'HTTP ' + httpStatus + '\n' + JSON.stringify(body, null, 2)
      })
      .catch((e) => {
        this.result = 'request failed: ' + e.message
      })
      .finally(() => {
        this.firing = false
      })
  },
}))

Alpine.start()
