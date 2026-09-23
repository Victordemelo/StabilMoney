@extends('errors::minimal')

{{-- O erro mais comum do uso real: formulário (ou a tela de login) aberto por mais tempo
     que a sessão dura. O mais importante é dizer que NADA foi salvo — senão a pessoa
     não sabe se precisa lançar de novo. --}}
@section('title', 'Página expirada')
@section('code', '419')
@section('message', 'Esta página ficou aberta tempo demais')
@section('explicacao', 'Por segurança, um formulário parado por muito tempo deixa de valer, e o envio não foi aceito: nada foi salvo. Recarregue a página do formulário e envie de novo.')
