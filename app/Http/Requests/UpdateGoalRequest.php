<?php

namespace App\Http\Requests;

/**
 * Mesmas regras da criação — a posse da meta em si
 * é verificada pela GoalPolicy no controller.
 */
class UpdateGoalRequest extends StoreGoalRequest {}
