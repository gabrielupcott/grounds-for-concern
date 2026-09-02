package engine

import (
	"fmt"
	"math/rand"
	"testing"
	"time"
)

func d(s string) time.Time {
	t, err := ParseDate(s)
	if err != nil {
		panic(err)
	}
	return t
}

func txn(day, merchant, category string, cents int64) Transaction {
	return Transaction{OccurredOn: day, Merchant: merchant, Category: category, AmountCents: cents}
}

func TestMatchesCondition(t *testing.T) {
	tests := []struct {
		name string
		txn  Transaction
		cond Condition
		want bool
	}{
		{"is coffee", txn("2026-09-01", "Tim Hortons", "coffee", 475), Condition{"category", "is", "coffee"}, true},
		{"is case-insensitive", txn("2026-09-01", "Tim Hortons", "coffee", 475), Condition{"category", "is", "Coffee"}, true},
		{"is wrong category", txn("2026-09-01", "Tim Hortons", "food", 475), Condition{"category", "is", "coffee"}, false},
		{"is_not merchant", txn("2026-09-01", "Starbucks", "coffee", 475), Condition{"merchant", "is_not", "starbucks"}, false},
		{"is_not other merchant", txn("2026-09-01", "Tim Hortons", "coffee", 475), Condition{"merchant", "is_not", "Starbucks"}, true},
		{"contains", txn("2026-09-01", "Williams Coffee Pub", "coffee", 475), Condition{"merchant", "contains", "coffee"}, true},
		{"contains case-insensitive", txn("2026-09-01", "Williams Coffee Pub", "coffee", 475), Condition{"merchant", "contains", "COFFEE"}, true},
		{"contains no match", txn("2026-09-01", "Tim Hortons", "coffee", 475), Condition{"merchant", "contains", "espresso"}, false},
		{"unknown field", txn("2026-09-01", "Tim Hortons", "coffee", 475), Condition{"amount", "is", "1"}, false},
		{"unknown operator", txn("2026-09-01", "Tim Hortons", "coffee", 475), Condition{"merchant", "starts_with", "T"}, false},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if got := MatchesCondition(tt.txn, tt.cond); got != tt.want {
				t.Errorf("MatchesCondition(%+v, %+v) = %v, want %v", tt.txn, tt.cond, got, tt.want)
			}
		})
	}
}

func TestMatchesGroup(t *testing.T) {
	coffee := Condition{"category", "is", "coffee"}
	starbucks := Condition{"merchant", "is", "starbucks"}

	tests := []struct {
		name string
		txn  Transaction
		grp  Group
		want bool
	}{
		{
			"all: both hold",
			txn("2026-09-01", "Starbucks", "coffee", 475),
			Group{"all", []Condition{coffee, starbucks}},
			true,
		},
		{
			"all: one fails",
			txn("2026-09-01", "Tim Hortons", "coffee", 475),
			Group{"all", []Condition{coffee, starbucks}},
			false,
		},
		{
			"any: one holds",
			txn("2026-09-01", "Tim Hortons", "coffee", 475),
			Group{"any", []Condition{coffee, starbucks}},
			true,
		},
		{
			"any: none hold",
			txn("2026-09-01", "Fortinos", "groceries", 4000),
			Group{"any", []Condition{coffee, starbucks}},
			false,
		},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if got := MatchesGroup(tt.txn, tt.grp); got != tt.want {
				t.Errorf("MatchesGroup(%+v, %+v) = %v, want %v", tt.txn, tt.grp, got, tt.want)
			}
		})
	}
}

func TestValidate(t *testing.T) {
	valid := Rule{
		Name:       "Coffee budget",
		WindowDays: 7,
		Group:      Group{"all", []Condition{{"category", "is", "coffee"}}},
		Threshold:  Threshold{"total", ">", 60},
	}
	if err := valid.Validate(); err != nil {
		t.Fatalf("valid rule rejected: %v", err)
	}

	tests := []struct {
		name string
		rule Rule
	}{
		{"no name", Rule{"", 7, valid.Group, valid.Threshold}},
		{"window zero", Rule{"x", 0, valid.Group, valid.Threshold}},
		{"window too big", Rule{"x", 366, valid.Group, valid.Threshold}},
		{"no conditions", Rule{"x", 7, Group{"all", nil}, valid.Threshold}},
		{"bad group match", Rule{"x", 7, Group{"sometimes", valid.Group.Conditions}, valid.Threshold}},
		{"bad field", Rule{"x", 7, Group{"all", []Condition{{"amount", "is", "5"}}}, valid.Threshold}},
		{"bad operator", Rule{"x", 7, Group{"all", []Condition{{"category", "like", "coffee"}}}, valid.Threshold}},
		{"empty value", Rule{"x", 7, Group{"all", []Condition{{"category", "is", " "}}}, valid.Threshold}},
		{"bad metric", Rule{"x", 7, valid.Group, Threshold{"average", ">", 60}}},
		{"bad threshold op", Rule{"x", 7, valid.Group, Threshold{"total", "<", 60}}},
		{"negative value", Rule{"x", 7, valid.Group, Threshold{"total", ">", -1}}},
		{"fractional count", Rule{"x", 7, valid.Group, Threshold{"count", ">", 2.5}}},
	}
	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			if err := tt.rule.Validate(); err == nil {
				t.Errorf("invalid rule accepted: %+v", tt.rule)
			}
		})
	}
}

func demoRule() Rule {
	return Rule{
		Name:       "Coffee budget",
		WindowDays: 7,
		Group:      Group{"all", []Condition{{"category", "is", "coffee"}}},
		Threshold:  Threshold{"total", ">", 60},
	}
}

func TestEvaluate(t *testing.T) {
	rule := demoRule()
	asOn := d("2026-09-03")

	t.Run("window boundaries are inclusive", func(t *testing.T) {
		// 7-day window over [08-28 .. 09-03].
		txns := []Transaction{
			txn("2026-08-27", "Tim Hortons", "coffee", 5000), // day before window: excluded
			txn("2026-08-28", "Tim Hortons", "coffee", 1000), // first window day: included
			txn("2026-09-03", "Tim Hortons", "coffee", 2000), // as_on: included
			txn("2026-09-04", "Tim Hortons", "coffee", 4000), // future: excluded
		}
		v, err := Evaluate(rule, txns, asOn)
		if err != nil {
			t.Fatal(err)
		}
		if v.WindowTotalCents != 3000 {
			t.Errorf("WindowTotalCents = %d, want 3000", v.WindowTotalCents)
		}
		if v.TransactionCount != 2 {
			t.Errorf("TransactionCount = %d, want 2", v.TransactionCount)
		}
		if v.WindowStart != "2026-08-28" {
			t.Errorf("WindowStart = %s, want 2026-08-28", v.WindowStart)
		}
	})

	t.Run("fires only above strict threshold", func(t *testing.T) {
		txns := []Transaction{txn("2026-09-01", "Tim Hortons", "coffee", 6000)}
		v, _ := Evaluate(rule, txns, asOn) // exactly $60 with ">" must NOT fire
		if v.Triggered {
			t.Error("triggered at exactly the threshold with \">\" operator")
		}
		txns[0].AmountCents = 6001
		v, _ = Evaluate(rule, txns, asOn)
		if !v.Triggered {
			t.Error("did not trigger at $60.01 with \">\" operator")
		}
	})

	t.Run("inclusive threshold operator", func(t *testing.T) {
		r := rule
		r.Threshold = Threshold{"total", ">=", 30}
		txns := []Transaction{txn("2026-09-01", "Tim Hortons", "coffee", 3000)}
		v, _ := Evaluate(r, txns, asOn)
		if !v.Triggered {
			t.Error("did not trigger at exactly the threshold with \">=\" operator")
		}
	})

	t.Run("count metric", func(t *testing.T) {
		r := rule
		r.Threshold = Threshold{"count", ">=", 3}
		txns := []Transaction{
			txn("2026-09-01", "Tim Hortons", "coffee", 475),
			txn("2026-09-02", "Starbucks", "coffee", 525),
			txn("2026-09-02", "Fortinos", "groceries", 5000),
			txn("2026-09-03", "Second Cup", "coffee", 600),
		}
		v, _ := Evaluate(r, txns, asOn)
		if !v.Triggered {
			t.Error("did not trigger on 3 matching purchases")
		}
		if len(v.MatchingTransactions) != 3 {
			t.Errorf("MatchingTransactions = %d, want 3 (groceries must not match)", len(v.MatchingTransactions))
		}
	})

	t.Run("invalid rule errors", func(t *testing.T) {
		if _, err := Evaluate(Rule{}, nil, asOn); err == nil {
			t.Error("evaluate accepted an invalid rule")
		}
	})

	t.Run("bad transaction date errors", func(t *testing.T) {
		txns := []Transaction{txn("09/01/2026", "x", "coffee", 100)} // wrong format
		if _, err := Evaluate(rule, txns, asOn); err == nil {
			t.Error("evaluate accepted a malformed date")
		}
	})
}

func TestBacktestCollapsesConsecutiveFiringDays(t *testing.T) {
	rule := demoRule()

	// $61 of coffee on 09-01, still in window through 09-07:
	// days 09-01..09-07 all fire => ONE episode.
	txns := []Transaction{txn("2026-09-01", "Tim Hortons", "coffee", 6100)}
	res, err := Backtest(rule, txns, d("2026-08-25"), d("2026-09-15"))
	if err != nil {
		t.Fatal(err)
	}
	if res.EpisodeCount != 1 {
		t.Fatalf("EpisodeCount = %d, want 1 (consecutive firing days must collapse)", res.EpisodeCount)
	}
	e := res.Episodes[0]
	if e.Start != "2026-09-01" || e.End != "2026-09-07" {
		t.Errorf("episode span = %s..%s, want 2026-09-01..2026-09-07", e.Start, e.End)
	}
	if e.PeakTotalCents != 6100 || e.PeakDay != "2026-09-01" {
		t.Errorf("peak = %d on %s, want 6100 on 2026-09-01", e.PeakTotalCents, e.PeakDay)
	}
}

func TestBacktestSeparateEpisodes(t *testing.T) {
	rule := demoRule()
	// Two spikes 20 days apart => two episodes.
	txns := []Transaction{
		txn("2026-08-01", "Starbucks", "coffee", 6500),
		txn("2026-08-21", "Starbucks", "coffee", 7000),
	}
	res, err := Backtest(rule, txns, d("2026-07-25"), d("2026-09-15"))
	if err != nil {
		t.Fatal(err)
	}
	if res.EpisodeCount != 2 {
		t.Fatalf("EpisodeCount = %d, want 2", res.EpisodeCount)
	}
}

// Oracle test: the sliding-window backtest must agree with the naive
// "evaluate every single day" approach on arbitrary data.
func TestBacktestMatchesNaive(t *testing.T) {
	rng := rand.New(rand.NewSource(42))

	rules := []Rule{
		demoRule(),
		{Name: "Expensive coffee", WindowDays: 3, Group: Group{"all", []Condition{{"category", "is", "coffee"}}}, Threshold: Threshold{"total", ">=", 10}},
		{Name: "Any food or coffee", WindowDays: 14, Group: Group{"any", []Condition{{"category", "is", "coffee"}, {"category", "is", "food"}}}, Threshold: Threshold{"count", ">", 4}},
	}

	for ri, rule := range rules {
		// Random-ish 60 days of transactions, 0-4 per day.
		var txns []Transaction
		cats := []string{"coffee", "coffee", "coffee", "food", "groceries", "transport", "other"}
		for i := 0; i < 60; i++ {
			day := d("2026-07-01").AddDate(0, 0, i).Format(dateLayout)
			for j := rng.Intn(5); j > 0; j-- {
				cat := cats[rng.Intn(len(cats))]
				txns = append(txns, txn(day, "Merchant", cat, int64(100+rng.Intn(800))))
			}
		}

		res, err := Backtest(rule, txns, d("2026-07-10"), d("2026-08-30"))
		if err != nil {
			t.Fatal(err)
		}

		// Naive oracle: one Evaluate per day; collect firing day sets.
		naiveFiring := map[string]bool{}
		for day := d("2026-07-10"); !day.After(d("2026-08-30")); day = day.AddDate(0, 0, 1) {
			v, err := Evaluate(rule, txns, day)
			if err != nil {
				t.Fatal(err)
			}
			if v.Triggered {
				naiveFiring[day.Format(dateLayout)] = true
			}
		}

		// Rebuild the firing-day set from episodes and compare.
		slidingFiring := map[string]bool{}
		for _, e := range res.Episodes {
			for day := d(e.Start); !day.After(d(e.End)); day = day.AddDate(0, 0, 1) {
				slidingFiring[day.Format(dateLayout)] = true
			}
		}

		if fmt.Sprint(slidingFiring) == "" && len(naiveFiring) == 0 && len(res.Episodes) == 0 {
			continue // both empty, fine
		}
		if len(naiveFiring) != len(slidingFiring) {
			t.Errorf("rule %d: naive fires %d days, sliding fires %d days", ri, len(naiveFiring), len(slidingFiring))
			continue
		}
		for day := range naiveFiring {
			if !slidingFiring[day] {
				t.Errorf("rule %d: day %s fires naively but not via sliding window", ri, day)
			}
		}
	}
}
