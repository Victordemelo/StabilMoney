@extends('errors::minimal')

{{-- Qualquer 4xx sem página própria (400, 408, 414…). O código vem da exceção; a
     mensagem dela, nunca. --}}
@section('title', 'Pedido inválido')
@section('code', isset($exception) && method_exists($exception, 'getStatusCode') ? (string) $exception->getStatusCode() : '4xx')
@section('message', 'Não foi possível atender a este pedido')
@section('explicacao', 'Algo no endereço ou no envio não está certo. Volte ao início e tente de novo pelo menu.')
