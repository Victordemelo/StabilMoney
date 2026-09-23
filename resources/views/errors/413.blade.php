@extends('errors::minimal')

{{-- O PHP recusa o POST inteiro antes de o Laravel ver o formulário — na prática, uma
     foto grande demais no perfil ou no cadastro de dependente. --}}
@section('title', 'Envio grande demais')
@section('code', '413')
@section('message', 'O envio passou do tamanho permitido')
@section('explicacao', 'Se você estava mandando uma foto, escolha um arquivo menor e tente de novo.')
