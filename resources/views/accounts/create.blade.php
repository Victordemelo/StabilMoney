@extends('layouts.app')

@section('title', 'Nova conta')

@section('content')
    <div class="section-head">
        <h2>Nova conta</h2>
        <span class="sub">Carteira, banco ou cartão</span>
    </div>

    @include('accounts._form', ['account' => null])
@endsection
