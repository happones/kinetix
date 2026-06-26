import { beforeEach, describe, expect, it } from "vitest";
import { useKinetixTour } from "@/composables/useKinetixTour";

const steps = [
  { target: "#a", title: "A" },
  { target: "#b", title: "B" },
];

describe("useKinetixTour", () => {
  beforeEach(() => localStorage.clear());

  it("walks forward and finishes on the last step", () => {
    const tour = useKinetixTour("t1", steps);
    tour.start();

    expect(tour.active.value).toBe(true);
    expect(tour.isFirst.value).toBe(true);
    expect(tour.current.value?.title).toBe("A");

    tour.next();
    expect(tour.index.value).toBe(1);
    expect(tour.isLast.value).toBe(true);

    tour.next(); // past the last → finish
    expect(tour.active.value).toBe(false);
    expect(tour.hasSeen()).toBe(true);
  });

  it("prev never goes below the first step", () => {
    const tour = useKinetixTour("t2", steps);
    tour.start();
    tour.prev();
    expect(tour.index.value).toBe(0);
  });

  it("startOnce only starts when not seen before", () => {
    const first = useKinetixTour("t3", steps);
    first.startOnce();
    expect(first.active.value).toBe(true);
    first.skip();

    const second = useKinetixTour("t3", steps);
    second.startOnce();
    expect(second.active.value).toBe(false);
  });

  it("reset clears the seen flag so it can run again", () => {
    const tour = useKinetixTour("t4", steps);
    tour.start();
    tour.finish();
    expect(tour.hasSeen()).toBe(true);

    tour.reset();
    expect(tour.hasSeen()).toBe(false);
  });
});
