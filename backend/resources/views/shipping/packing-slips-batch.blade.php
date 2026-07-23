<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Packing Slips ({{ count($slips) }})</title>
@include('shipping._slip_styles')
</head>
<body>
@foreach($slips as $i => $slip)
    <div class="{{ $i < count($slips) - 1 ? 'pagebreak' : '' }}">
        @include('shipping._slip', $slip)
    </div>
@endforeach
</body>
</html>
