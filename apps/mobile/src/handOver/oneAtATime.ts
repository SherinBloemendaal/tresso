/**
 * Runs one task at a time and knows at once that one is running, unlike state, which a second tap before the screen
 * updates would not see yet: a task asked for while another runs is dropped, so a double tap never hands over or
 * nudges twice.
 */
export class OneAtATime {
  private running = false

  /** Runs the task unless another one is running; resolves whether it ran. */
  async run(task: () => Promise<void>): Promise<boolean> {
    if (this.running) {
      return false
    }
    this.running = true
    try {
      await task()
      return true
    } finally {
      this.running = false
    }
  }
}
