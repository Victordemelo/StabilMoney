@extends('errors::minimal')

{{-- Num app de dinheiro, o conselho que importa: conferir antes de repetir — o erro
     pode ter vindo depois de a gravação já ter acontecido. --}}
@section('title', 'Erro no servidor')
@section('code', '500')
@section('message', 'Algo deu errado do nosso lado')
@section('explicacao', 'Não foi nada que você fez, e o erro ficou registrado para ser corrigido. Se você estava salvando alguma coisa, confira se ela entrou antes de tentar de novo.')
