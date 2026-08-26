@extends('layouts.app')
@section('title','Masuk')
@section('content')
<div style="max-width:400px;margin:8vh auto">
  <div style="text-align:center;margin-bottom:26px">
    <h1 style="margin-bottom:6px">Less Worry</h1>
    <div class="sub" style="margin:0">Complaint Management</div>
  </div>
  <div class="card">
    <form method="POST" action="{{ route('login') }}">
      @csrf
      <label>Email</label>
      <input type="email" name="email" value="{{ old('email') }}" required autofocus>
      <label>Password</label>
      <input type="password" name="password" required>
      <div style="margin-top:18px"><button style="width:100%">Masuk</button></div>
    </form>
  </div>
</div>
@endsection
