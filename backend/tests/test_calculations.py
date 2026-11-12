from decimal import Decimal

import pytest

from app.calculations import calculate_back_total, round_work_minutes


@pytest.mark.parametrize(("kind", "expected"), [
    ("percentage", "200.00"),
    ("fixed_per_unit", "100.00"),
    ("none", "0.00"),
])
def test_back_total(kind, expected):
    assert calculate_back_total(Decimal("1000"), Decimal("2"),
                                Decimal("10") if kind == "percentage" else Decimal("50"),
                                kind) == Decimal(expected)


@pytest.mark.parametrize(("mode", "expected"), [
    ("floor", 60),
    ("half_up", 60),
    ("ceil", 75),
])
def test_quarter_hour_rounding(mode, expected):
    assert round_work_minutes(67, 15, mode) == expected


def test_allocation_inputs_are_rejected_when_invalid():
    with pytest.raises(ValueError):
        calculate_back_total(Decimal("-1"), Decimal("1"), Decimal("5"), "percentage")
