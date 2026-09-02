// Package engine is a small, stateless rule engine for spending rules.
//
// It knows nothing about databases or HTTP: callers hand it a Rule and a
// slice of Transactions, it returns verdicts. That makes every code path
// (live evaluation, backtesting, tests) run through the exact same logic.
package engine

import (
	"errors"
	"fmt"
	"math"
	"strings"
)

// Condition selects transactions by one field. All string comparison is
// case-insensitive.
type Condition struct {
	Field    string `json:"field"`    // "category" | "merchant"
	Operator string `json:"operator"` // "is" | "is_not" | "contains"
	Value    string `json:"value"`
}

// Group combines conditions to decide which transactions a rule watches.
type Group struct {
	Match      string      `json:"match"` // "any" | "all"
	Conditions []Condition `json:"conditions"`
}

// Threshold is the line a rule fires across. "total" is compared in dollars
// (the wire format is cents); "count" is a number of purchases.
type Threshold struct {
	Metric    string  `json:"metric"`   // "total" | "count"
	Operator  string  `json:"operator"` // ">" | ">="
	Value     float64 `json:"value"`
}

// Rule is the complete DSL document. Stored as JSON in MySQL, built by the
// PHP UI, interpreted here.
type Rule struct {
	Name       string    `json:"name"`
	WindowDays int       `json:"window_days"`
	Group      Group     `json:"group"`
	Threshold  Threshold `json:"threshold"`
}

// Validate enforces the DSL contract. The HTTP layer rejects anything that
// fails here before evaluation.
func (r Rule) Validate() error {
	if strings.TrimSpace(r.Name) == "" {
		return errors.New("rule name is required")
	}
	if r.WindowDays < 1 || r.WindowDays > 365 {
		return fmt.Errorf("window_days must be 1..365, got %d", r.WindowDays)
	}
	if len(r.Group.Conditions) == 0 {
		return errors.New("at least one condition is required")
	}
	if r.Group.Match != "any" && r.Group.Match != "all" {
		return fmt.Errorf("group match must be \"any\" or \"all\", got %q", r.Group.Match)
	}
	for i, c := range r.Group.Conditions {
		switch c.Field {
		case "category", "merchant":
		default:
			return fmt.Errorf("condition %d: field must be \"category\" or \"merchant\", got %q", i, c.Field)
		}
		switch c.Operator {
		case "is", "is_not", "contains":
		default:
			return fmt.Errorf("condition %d: operator must be \"is\", \"is_not\" or \"contains\", got %q", i, c.Operator)
		}
		if strings.TrimSpace(c.Value) == "" {
			return fmt.Errorf("condition %d: value is required", i)
		}
	}
	switch r.Threshold.Metric {
	case "total", "count":
	default:
		return fmt.Errorf("threshold metric must be \"total\" or \"count\", got %q", r.Threshold.Metric)
	}
	switch r.Threshold.Operator {
	case ">", ">=":
	default:
		return fmt.Errorf("threshold operator must be \">\" or \">=\", got %q", r.Threshold.Operator)
	}
	if r.Threshold.Value < 0 {
		return errors.New("threshold value must be >= 0")
	}
	if r.Threshold.Metric == "count" && r.Threshold.Value != math.Trunc(r.Threshold.Value) {
		return errors.New("count threshold must be a whole number")
	}
	return nil
}
