"""Pure calculation helpers used by POS services."""
from decimal import Decimal, ROUND_HALF_UP


def money(value: Decimal) -> Decimal:
    return Decimal(value).quantize(Decimal("0.01"), rounding=ROUND_HALF_UP)


def calculate_back_total(unit_price: Decimal, quantity: Decimal,
                         back_rate: Decimal, back_type: str) -> Decimal:
    if unit_price < 0 or quantity <= 0 or back_rate < 0:
        raise ValueError("Invalid price, quantity or back rate")
    if back_type == "percentage":
        return money(unit_price * quantity * back_rate / Decimal("100"))
    if back_type == "fixed_per_unit":
        return money(back_rate * quantity)
    if back_type == "none":
        return Decimal("0.00")
    raise ValueError("Unsupported back type")


def round_work_minutes(minutes: int, unit: int, mode: str) -> int:
    if minutes < 0 or unit <= 0:
        raise ValueError("Minutes must be non-negative and unit must be positive")
    if mode == "floor":
        return (minutes // unit) * unit
    if mode == "ceil":
        return ((minutes + unit - 1) // unit) * unit
    if mode == "half_up":
        return int((Decimal(minutes) / Decimal(unit)).quantize(
            Decimal("1"), rounding=ROUND_HALF_UP
        )) * unit
    raise ValueError("Unsupported rounding mode")
