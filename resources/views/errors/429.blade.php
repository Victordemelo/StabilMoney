@extends('errors::minimal')

{{-- Quanto esperar vem do `Retry-After` do limite de tentativas; a moldura
     (errors/minimal) o transforma em "Tente de novo em 42 segundos". --}}
@section('title', 'Muitas tentativas')
@section('code', '429')
@section('message', 'Muitas tentativas seguidas')
@section('explicacao', 'Por segurança, esta ação fica bloqueada por um tempo depois de várias tentativas seguidas.')
