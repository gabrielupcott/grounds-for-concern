package engine

import (
	"fmt"
	"time"
)

// Transaction is one spending record. Amount is integer cents; the date is
// date-only, YYYY-MM-DD (UTC), because a budget rule never cares about clocks.
type Transaction struct {
	OccurredOn  string `json:"occurred_on"`
	Merchant    string `json:"merchant"`
	Category    string `json:"category"`
	AmountCents int64  `json:"amount_cents"`
}

// Verdict is the result of evaluating a rule for one day.
type Verdict struct {
	Triggered            bool          `json:"triggered"`
	AsOn                 string        `json:"as_on"`
	WindowDays           int           `json:"window_days"`
	WindowStart          string        `json:"window_start"`
	TransactionCount     int           `json:"transaction_count"`
	WindowTotalCents     int64         `json:"window_total_cents"`
	ThresholdMetric      string        `json:"threshold_metric"`
	ThresholdOperator    string        `json:"threshold_operator"`
	ThresholdValue       float64       `json:"threshold_value"`
	MatchingTransactions []Transaction `json:"matching_transactions"`
}

const dateLayout = "2006-01-02"

// ParseDate parses a YYYY-MM-DD string.
func ParseDate(s string) (time.Time, error) {
	t, err := time.Parse(dateLayout, s)
	if err != nil {
		return t, fmt.Errorf("invalid date %q (want YYYY-MM-DD)", s)
	}
	return t, nil
}

// MeetsThreshold applies the threshold to a window aggregate.
func MeetsThreshold(th Threshold, totalCents int64, count int) bool {
	var actual float64
	if th.Metric == "total" {
		actual = float64(totalCents) / 100
	} else {
		actual = float64(count)
	}
	if th.Operator == ">" {
		return actual > th.Value
	}
	return actual >= th.Value
}

// Evaluate runs a rule against transactions for the day ending asOn.
// The window is inclusive: [asOn - (window_days - 1) .. asOn].
func Evaluate(rule Rule, txns []Transaction, asOn time.Time) (Verdict, error) {
	if err := rule.Validate(); err != nil {
		return Verdict{}, err
	}
	start := asOn.AddDate(0, 0, -(rule.WindowDays - 1))

	v := Verdict{
		WindowDays:        rule.WindowDays,
		AsOn:              asOn.Format(dateLayout),
		WindowStart:       start.Format(dateLayout),
		ThresholdMetric:   rule.Threshold.Metric,
		ThresholdOperator: rule.Threshold.Operator,
		ThresholdValue:    rule.Threshold.Value,
	}

	for _, t := range txns {
		d, err := ParseDate(t.OccurredOn)
		if err != nil {
			return Verdict{}, err
		}
		if d.Before(start) || d.After(asOn) {
			continue
		}
		if !MatchesGroup(t, rule.Group) {
			continue
		}
		v.WindowTotalCents += t.AmountCents
		v.TransactionCount++
		v.MatchingTransactions = append(v.MatchingTransactions, t)
	}

	v.Triggered = MeetsThreshold(rule.Threshold, v.WindowTotalCents, v.TransactionCount)
	return v, nil
}
