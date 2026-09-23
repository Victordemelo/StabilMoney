@extends('errors::minimal')

{{-- Acontece com endereço que só recebe formulário (o de sair, por exemplo) aberto pela
     barra do navegador ou por um favorito. --}}
@section('title', 'Endereço inválido')
@section('code', '405')
@section('message', 'Este endereço não abre direto no navegador')
@section('explicacao', 'Ele só funciona a partir de um botão ou formulário do próprio app. Volte ao início e siga pelo menu.')
