@php $m = $complaint->slaMeter(); @endphp
<div class="sla {{ $m['state'] }}">
  <div class="t">{{ $m['label'] }}</div>
  <div class="track"><i style="width:{{ $m['pct'] }}%"></i></div>
</div>
