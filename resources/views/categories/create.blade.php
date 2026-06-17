@extends('layouts.app')

@section('title', 'Nova categoria')

@section('content')
    <div class="section-head">
        <h2>Nova categoria</h2>
        <span class="sub">Para organizar receitas e despesas</span>
    </div>

    @include('categories._form', ['category' => null])
@endsection
