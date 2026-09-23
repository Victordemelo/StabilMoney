@extends('errors::minimal')

{{-- Nunca a mensagem da exceção (o 403 do framework a mostrava): o texto cobre as duas
     causas reais deste app — link assinado vencido e área que não é da conta. --}}
@section('title', 'Acesso negado')
@section('code', '403')
@section('message', 'Você não tem acesso a esta página')
@section('explicacao', 'O link pode ter expirado — os que mandamos por e-mail valem por tempo limitado — ou esta área não está liberada para a sua conta. Algumas telas são só do titular da família.')
