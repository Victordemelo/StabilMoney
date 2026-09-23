@extends('errors::minimal')

{{-- ⚠️ Não ecoar o endereço pedido: o 404 do painel desligado tem de ser idêntico ao de
     qualquer URL inexistente (ver o comentário do errors/minimal). --}}
@section('title', 'Página não encontrada')
@section('code', '404')
@section('message', 'Não encontramos esta página')
@section('explicacao', 'O endereço pode ter sido digitado errado, ou o que ele mostrava foi excluído.')
