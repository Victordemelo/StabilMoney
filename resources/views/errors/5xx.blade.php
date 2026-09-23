@extends('errors::minimal')

{{-- Qualquer 5xx sem página própria (502, 504…). O código vem da exceção; a mensagem
     dela, nunca. --}}
@section('title', 'Serviço indisponível')
@section('code', isset($exception) && method_exists($exception, 'getStatusCode') ? (string) $exception->getStatusCode() : '5xx')
@section('message', 'Algo deu errado do nosso lado')
@section('explicacao', 'O Stabil Money não conseguiu responder agora. Tente de novo em alguns minutos.')
